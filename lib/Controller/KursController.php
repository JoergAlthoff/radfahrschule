<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use DateTimeImmutable;
use OCA\Radfahrschule\Benachrichtigung\Nachricht;
use OCA\Radfahrschule\Benachrichtigung\Versandergebnis;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\Benachrichtigen;
use OCA\Radfahrschule\Fachlogik\Frist;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Kursloeschen;
use OCA\Radfahrschule\Fachlogik\Kurszeile;
use OCA\Radfahrschule\Fachlogik\KursNichtGefunden;
use OCA\Radfahrschule\Fachlogik\LoeschenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\INavigationManager;
use OCP\IRequest;

class KursController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Formulare $formulare,
		private readonly Kursloeschen $kursloeschen,
		private readonly Verwaltungsrecht $recht,
		private readonly Zugangsdaten $zugangsdaten,
		private readonly ITimeFactory $timeFactory,
		private readonly INavigationManager $navigationManager,
		private readonly Titelmuster $titelmuster,
		private readonly Frist $frist,
		private readonly Zeitzone $zeitzone,
		private readonly Benachrichtigen $benachrichtigen,
		private readonly Betreiberangaben $betreiberangaben,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Die Detailseite. Der Loeschknopf fuehrt von hier auf die Nachfrage,
	 * nicht gleich aufs Loeschen: DELETE nimmt die Antworten mit, ohne zu
	 * fragen, und es gibt keinen Papierkorb.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/kurs/{id}')]
	public function zeige(string $id): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Diese App darf nur öffnen, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht — oder Administrator ist.');
		}

		// Ein Platzhalter matcht genau ein Pfadsegment. Welche Route ein
		// zweisegmentiger Pfad trifft, haengt von der Einlesereihenfolge ab
		// und ist je Instanz anders. Der Controller prueft den Wert deshalb
		// selbst, statt sich darauf zu verlassen.
		if (!ctype_digit($id)) {
			return $this->meldung('Unbekannter Kurs', 'Das ist keine Formularnummer.');
		}

		try {
			$alleKurse = Kursliste::ausFormularen($this->formulare->alleEigenen(), $this->titelmuster);
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		$kurs = $this->kursMitId($alleKurse, (int)$id);
		if ($kurs === null) {
			return $this->meldung('Unbekannter Kurs',
				'Zu dieser Nummer gibt es keinen Kurs. Vorlagen zählen nicht dazu.');
		}

		try {
			$zeilen = $this->zeilenMitLink($kurs);
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		return new TemplateResponse($this->appName, 'kurs', [
			'pageTitle' => $kurs->kennung,
			'id' => $kurs->eineId(),
			'kennung' => $kurs->kennung,
			'zeilen' => $zeilen,
			'kurstag' => $kurs->kurstagSatz($this->jetzt()),
			'frist' => $this->frist->text($kurs->letzterTag, $this->jetzt()),
			'doppelt' => $kurs->istDoppelt(),
			'fehlendeHaelfte' => $kurs->fehlendeHaelfte() ?? '',
		]);
	}

	/**
	 * Die Nachfrage vor dem Loeschen. Sie liest nur.
	 *
	 * Sie nennt jedes Formular mit seinem Zaehlerstand, damit vor dem
	 * Zusagen dasteht, wie viele Anmeldungen mitgehen.
	 *
	 * Die Kennung reist im POST-Koerper: Sie traegt Schraegstriche und
	 * passt in keinen Pfad.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/loeschen/nachfrage')]
	public function nachfrage(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Kurse löschen darf nur, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht.');
		}

		$kennung = (string)$this->request->getParam('kennung', '');
		if ($kennung === '') {
			return $this->meldung('Die Angaben passen nicht',
				'Es kam kein Kurs mit. Bitte von der Übersicht neu beginnen.');
		}

		try {
			$kurs = $this->kursMitKennung($kennung);
			$grundOhneAbsage = $this->grundOhneAbsage($kurs);
		} catch (KursNichtGefunden $fehler) {
			return $this->meldung('Unbekannter Kurs', $fehler->getMessage());
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		return $this->nachfrageseite(
			$kurs,
			$this->betreiberangaben->absageBetreff(),
			$this->betreiberangaben->absageTextAngemeldete(),
			$this->betreiberangaben->absageTextWartende(),
			'',
			$grundOhneAbsage,
		);
	}

	/**
	 * Die Nachfrage vor dem Loeschen, mit den Feldern der Absage.
	 *
	 * Sie nennt jedes Formular mit seinem Zaehlerstand, damit vor dem
	 * Zusagen dasteht, wie viele Anmeldungen mitgehen.
	 */
	private function nachfrageseite(
		Kurs $kurs,
		string $betreff,
		string $textAngemeldete,
		string $textWartende,
		string $fehler,
		string $grundOhneAbsage,
	): TemplateResponse {
		return new TemplateResponse($this->appName, 'loeschnachfrage', [
			'pageTitle' => 'Kurs löschen',
			'id' => $kurs->eineId(),
			'kennung' => $kurs->kennung,
			'kurstag' => $kurs->kurstagSatz($this->jetzt()),
			// Jede Zeile geht weg, auch die doppelt angelegten. Stuenden
			// hier nur Anmeldung und Warteliste, verschwaenden Formulare
			// samt Anmeldedaten ungenannt.
			'zeilen' => array_map(
				static fn (Kurszeile $zeile): array => [
					'beschriftung' => $zeile->beschriftung,
					'abgaben' => $zeile->formular->abgaben,
				], $kurs->zeilen()),
			'fehlendeHaelfte' => $kurs->fehlendeHaelfte() ?? '',
			'betreff' => $betreff,
			'textAngemeldete' => $textAngemeldete,
			'textWartende' => $textWartende,
			'fehler' => $fehler,
			'grundOhneAbsage' => $grundOhneAbsage,
		]);
	}

	/**
	 * Warum keine Absage moeglich ist, oder ''.
	 *
	 * Geloescht werden kann trotzdem. Ein Kurs darf nicht stehen bleiben,
	 * weil der Mailversand fehlt.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	private function grundOhneAbsage(Kurs $kurs): string {
		if ($this->benachrichtigen->versandIstAbgeschaltet()) {
			return 'Auf dieser Nextcloud ist der Mailversand abgeschaltet. Der Kurs '
				. 'lässt sich trotzdem löschen, nur ohne Absage.';
		}
		$fehlendeAngabe = $this->benachrichtigen->fehlendeAngabe($kurs);
		if ($fehlendeAngabe === null) {
			return '';
		}
		return $fehlendeAngabe . ' Der Kurs lässt sich trotzdem löschen, nur ohne Absage.';
	}

	/**
	 * Die Berechtigung wird hier ein ZWEITES Mal geprueft. Auf der
	 * Detailseite fehlt der Knopf, aber ein fehlender Knopf ist keine
	 * Sicherung: Den POST kann jeder von Hand schicken.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/loeschen')]
	public function loesche(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Kurse löschen darf nur, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht.');
		}

		$kennung = (string)$this->request->getParam('kennung', '');
		if ($kennung === '') {
			return $this->meldung('Die Angaben passen nicht',
				'Es kam kein Kurs mit. Bitte von der Übersicht neu beginnen.');
		}

		try {
			$kurs = $this->kursMitKennung($kennung);
			$grundOhneAbsage = $this->grundOhneAbsage($kurs);
		} catch (KursNichtGefunden $fehler) {
			return $this->meldung('Unbekannter Kurs', $fehler->getMessage());
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		$betreff = $this->feld('betreff');
		$textAngemeldete = $this->feld('textAngemeldete');
		$textWartende = $this->feld('textWartende');
		$einTextSteht = $textAngemeldete !== '' || $textWartende !== '';
		$absageGewollt = $grundOhneAbsage === '' && $einTextSteht;

		if ($absageGewollt && $betreff === '') {
			return $this->nachfrageseite($kurs, $betreff, $textAngemeldete, $textWartende,
				'Es ist nichts gelöscht. Für die Absage fehlt der Betreff. Wer keine '
				. 'Absage schicken will, leert beide Texte.', $grundOhneAbsage);
		}

		// Die Adressen VOR dem Loeschen: Danach gibt es sie nicht mehr.
		$auftraege = [];
		if ($absageGewollt) {
			try {
				$auftraege = $this->absageauftraege($kurs, $betreff, $textAngemeldete, $textWartende);
			} catch (FormulareNichtErreichbar $fehler) {
				return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
			}
		}

		try {
			$geloescht = $this->kursloeschen->loesche(
				$kennung, $this->recht->benutzer(), $this->jetzt());
		} catch (KursNichtGefunden $fehler) {
			return $this->meldung('Unbekannter Kurs', $fehler->getMessage());
		} catch (GeradeBeschaeftigt) {
			// Jemand legt gerade an oder verschiebt. Dieselbe Sperre, damit
			// niemand einem laufenden Vorgang die Formulare wegzieht.
			return $this->meldung('Gerade beschäftigt',
				'Es läuft gerade ein anderer Vorgang. Bitte in ein paar '
				. 'Sekunden noch einmal versuchen.');
		} catch (LoeschenFehlgeschlagen $fehler) {
			// Ein Teil des Kurses kann schon geloescht sein.
			$meldungstext = $fehler->getMessage();
			if ($absageGewollt) {
				$meldungstext .= "\n\nEs ist keine Absage hinausgegangen. Die Adressen "
					. 'aus einem schon gelöschten Formular gibt es nicht mehr.';
			}
			return $this->meldung('Das Löschen brach ab', $meldungstext,
				vorformatiert: true);
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		$ergebnis = null;
		if ($absageGewollt) {
			$ergebnis = $this->benachrichtigen->schicke(
				$geloescht, $auftraege, $this->recht->benutzer(), $this->jetzt());
		}

		return new TemplateResponse($this->appName, 'geloescht',
			$this->loeschmeldung($geloescht, $ergebnis));
	}

	/**
	 * Liest die Adressen und baut die Absage je Liste. Verschickt nichts.
	 *
	 * @return list<array{empfaenger: Empfaenger, nachricht: Nachricht}>
	 * @throws FormulareNichtErreichbar
	 */
	private function absageauftraege(
		Kurs $kurs,
		string $betreff,
		string $textAngemeldete,
		string $textWartende,
	): array {
		$anAngemeldete = $this->benachrichtigen->nachricht($kurs, $betreff, $textAngemeldete);
		$anWartende = $this->benachrichtigen->nachricht($kurs, $betreff, $textWartende);
		return $this->benachrichtigen->auftraege($kurs, $anAngemeldete, $anWartende);
	}

	private function feld(string $name): string {
		return trim((string)$this->request->getParam($name, ''));
	}

	/**
	 * Sucht den Kurs, zu dem ein Formular gehoert.
	 *
	 * Eine Vorlagen-id findet nichts: Vorlagen sind gar nicht erst in der
	 * Liste. Das ist die Sperre, die verhindert, dass die Detailseite eine
	 * Vorlage zum Loeschen anbietet.
	 *
	 * @param list<Kurs> $kurse
	 */
	private function kursMitId(array $kurse, int $formularId): ?Kurs {
		foreach ($kurse as $kurs) {
			foreach ($kurs->zeilen() as $zeile) {
				if ($zeile->formular->id === $formularId) {
					return $kurs;
				}
			}
		}
		return null;
	}

	/**
	 * Sucht den Kurs zu einer Kennung.
	 *
	 * @throws FormulareNichtErreichbar
	 * @throws KursNichtGefunden
	 */
	private function kursMitKennung(string $kennung): Kurs {
		foreach (Kursliste::ausFormularen($this->formulare->alleEigenen(), $this->titelmuster) as $kurs) {
			if ($kurs->kennung === $kennung) {
				return $kurs;
			}
		}
		throw new KursNichtGefunden($kennung);
	}

	/**
	 * Holt zu jedem Formular des Kurses den oeffentlichen Link.
	 *
	 * Das kostet je Formular einen Aufruf: Die Liste liefert keine shares.
	 * Genau deshalb steht der Link auf dieser Seite und nicht in der
	 * Uebersicht.
	 *
	 * @return list<array{beschriftung:string, id:int, abgaben:int, link:string}>
	 * @throws FormulareNichtErreichbar
	 */
	private function zeilenMitLink(Kurs $kurs): array {
		$zeilen = [];

		foreach ($kurs->zeilen() as $zeile) {
			$gelesen = $this->formulare->formularHolen($zeile->formular->id);
			$hash = $gelesen->oeffentlicherHash();

			$zeilen[] = [
				'beschriftung' => $zeile->beschriftung,
				'id' => $zeile->formular->id,
				'abgaben' => $gelesen->abgaben,
				'link' => $hash === null ? '' : $this->freigabeLink($hash),
			];
		}

		return $zeilen;
	}

	/**
	 * Fuellt die Seite nach dem Loeschen.
	 *
	 * "fehlend" ist gesetzt, wenn nur eine Haelfte wegging. Dann darf die
	 * Seite NICHT "samt Anmeldedaten entfernt" melden: Die andere Haelfte
	 * steht noch in Nextcloud, ihre Eintraege auch, und die Loeschfrist
	 * laeuft weiter.
	 *
	 * "absage" ist leer, wenn keine Absage gewollt war.
	 *
	 * @return array<string, mixed>
	 */
	private function loeschmeldung(Kurs $kurs, ?Versandergebnis $absage): array {
		$fehlend = $kurs->fehlendeHaelfte();
		$zeilen = $kurs->zeilen();

		return [
			'pageTitle' => 'Kurs gelöscht',
			'kennung' => $kurs->kennung,
			'fehlend' => $fehlend ?? '',
			// Zeilen() ist zugleich die Loeschliste - JEDE Zeile ging weg,
			// also muss auch jede genannt werden. Stuende hier nur die
			// erste, verschwaenden bei einem doppelt angelegten Kurs
			// Formulare samt Anmeldedaten ungenannt.
			'geloeschte' => array_map(
				static fn (Kurszeile $zeile): string => $zeile->beschriftung, $zeilen),
			// Leer, wenn keine Absage gewollt war. Dann schweigt die Seite
			// darueber, statt "keine Nachricht" zu melden.
			'absage' => $absage?->satz() ?? '',
			'gescheitert' => $absage->gescheitert ?? [],
		];
	}

	private function meldung(
		string $titel,
		string $meldung,
		bool $vorformatiert = false,
	): TemplateResponse {
		return new TemplateResponse($this->appName, 'meldung', [
			'pageTitle' => $titel,
			'titel' => $titel,
			'meldung' => $meldung,
			'vorformatiert' => $vorformatiert,
		]);
	}

	private function freigabeLink(string $hash): string {
		return rtrim($this->zugangsdaten->basisUrl(), '/') . '/apps/forms/s/' . $hash;
	}

	private function jetzt(): DateTimeImmutable {
		return $this->timeFactory->now()->setTimezone($this->zeitzone->desVereins());
	}
}
