<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use DateTimeImmutable;
use OCA\Radfahrschule\Fachlogik\Benachrichtigen;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;

/**
 * Eine Nachricht an die Eintraege eines Kurses.
 *
 * Schreibseite und Versand sind POST. Die Kennung traegt Schraegstriche und
 * passt in keinen Pfad, und eine GET-Route mit Platzhalter koennte mit einer
 * anderen kollidieren. Die Ergebnisseite ist GET und kommt ohne Platzhalter
 * aus, ihre Angaben liegen in der Sitzung.
 */
class BenachrichtigenController extends Controller {
	/** Unter diesem Schluessel wartet das Ergebnis in der Sitzung auf seine Seite. */
	private const SITZUNG_ERGEBNIS = 'radfahrschule_versandergebnis';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Formulare $formulare,
		private readonly Benachrichtigen $benachrichtigen,
		private readonly Verwaltungsrecht $recht,
		private readonly ITimeFactory $timeFactory,
		private readonly INavigationManager $navigationManager,
		private readonly Titelmuster $titelmuster,
		private readonly Zeitzone $zeitzone,
		private readonly ISession $session,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	/** Die Schreibseite. Sie liest Zahlen, keine Adressen. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/nachricht')]
	public function formular(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$geprueft = $this->kursOderMeldung();
		if ($geprueft instanceof TemplateResponse) {
			return $geprueft;
		}

		return $this->schreibseite($geprueft, '', '', '', '');
	}

	/**
	 * Der Versand.
	 *
	 * Alle Pruefungen laufen hier ein zweites Mal. Auf der Kursseite fehlt
	 * der Knopf ohne Recht, aber den POST kann jeder von Hand schicken.
	 *
	 * Nach dem Versand leitet die App um. Liesse sie die Ergebnisseite als
	 * Antwort auf den POST stehen, schickte ein Neuladen alle Mails noch einmal.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/nachricht/senden')]
	public function sende(): TemplateResponse|RedirectResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$geprueft = $this->kursOderMeldung();
		if ($geprueft instanceof TemplateResponse) {
			return $geprueft;
		}
		$kurs = $geprueft;

		$betreff = $this->feld('betreff');
		$textAngemeldete = $this->feld('textAngemeldete');
		$textWartende = $this->feld('textWartende');

		if ($betreff === '' || ($textAngemeldete === '' && $textWartende === '')) {
			return $this->schreibseite($kurs, $betreff, $textAngemeldete, $textWartende,
				'Es ist nichts verschickt. Bitte einen Betreff und mindestens einen Text eintragen.');
		}

		$anAngemeldete = $this->benachrichtigen->nachricht($kurs, $betreff, $textAngemeldete);
		$anWartende = $this->benachrichtigen->nachricht($kurs, $betreff, $textWartende);

		try {
			$ergebnis = $this->benachrichtigen->verschicke(
				$kurs,
				$anAngemeldete,
				$anWartende,
				$this->recht->benutzer(),
				$this->jetzt(),
			);
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Nichts verschickt',
				'Die Adressen ließen sich nicht lesen. Es ist keine Nachricht '
				. 'hinausgegangen. ' . $fehler->getMessage());
		}

		$this->session->set(self::SITZUNG_ERGEBNIS, [
			'kennung' => $kurs->kennung,
			'id' => $kurs->eineId(),
			'satz' => $ergebnis->satz(),
			'gescheitert' => $ergebnis->gescheitert,
		]);

		$ergebnisadresse = $this->urlGenerator->linkToRoute('radfahrschule.benachrichtigen.verschickt');
		return new RedirectResponse($ergebnisadresse);
	}

	/**
	 * Die Ergebnisseite nach dem Versand.
	 *
	 * Das Ergebnis wird beim Anzeigen aus der Sitzung genommen. Wer die Seite
	 * danach neu laedt, landet auf der Uebersicht.
	 *
	 * Ohne CSRF-Pruefung, weil eine Umleitung keinen Token mitbringt. Die
	 * Seite verschickt nichts, sie zeigt nur, was in der eigenen Sitzung liegt.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/nachricht-verschickt')]
	public function verschickt(): TemplateResponse|RedirectResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		if (!$this->recht->darfVerwalten()) {
			return $this->ohneRecht();
		}

		$ergebnis = $this->session->get(self::SITZUNG_ERGEBNIS);
		if (!is_array($ergebnis)) {
			$uebersichtsadresse = $this->urlGenerator->linkToRoute('radfahrschule.uebersicht.index');
			return new RedirectResponse($uebersichtsadresse);
		}
		$this->session->remove(self::SITZUNG_ERGEBNIS);

		return new TemplateResponse($this->appName, 'benachrichtigt', [
			'pageTitle' => 'Nachricht verschickt',
			'kennung' => $ergebnis['kennung'],
			'id' => $ergebnis['id'],
			'satz' => $ergebnis['satz'],
			'gescheitert' => $ergebnis['gescheitert'],
		]);
	}

	/**
	 * Recht, Kennung, Versand, Kurs, Frage "email" - in dieser Reihenfolge.
	 *
	 * Gibt den Kurs zurueck oder die Seite, die sagt, woran es liegt.
	 */
	private function kursOderMeldung(): Kurs|TemplateResponse {
		if (!$this->recht->darfVerwalten()) {
			return $this->ohneRecht();
		}

		$kennung = $this->feld('kennung');
		if ($kennung === '') {
			return $this->meldung('Die Angaben passen nicht',
				'Es kam kein Kurs mit. Bitte von der Übersicht neu beginnen.');
		}

		if ($this->benachrichtigen->versandIstAbgeschaltet()) {
			return $this->meldung('Kein Mailversand',
				'Auf dieser Nextcloud ist der Mailversand abgeschaltet. Das lässt '
				. 'sich nur in der Konfiguration der Instanz ändern.');
		}

		try {
			$kurs = $this->kursMitKennung($kennung);
			if ($kurs === null) {
				return $this->meldung('Unbekannter Kurs',
					'Zu dieser Kennung gibt es keinen Kurs.');
			}
			$fehlt = $this->benachrichtigen->fehlendeAngabe($kurs);
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		if ($fehlt !== null) {
			return $this->meldung('Die Vorlage passt nicht', $fehlt);
		}

		return $kurs;
	}

	/** @throws FormulareNichtErreichbar */
	private function kursMitKennung(string $kennung): ?Kurs {
		foreach (Kursliste::ausFormularen($this->formulare->alleEigenen(), $this->titelmuster) as $kurs) {
			if ($kurs->kennung === $kennung) {
				return $kurs;
			}
		}
		return null;
	}

	private function schreibseite(
		Kurs $kurs,
		string $betreff,
		string $textAngemeldete,
		string $textWartende,
		string $fehler,
	): TemplateResponse {
		return new TemplateResponse($this->appName, 'benachrichtigung', [
			'pageTitle' => 'Nachricht an die Teilnehmer',
			'kennung' => $kurs->kennung,
			'id' => $kurs->eineId(),
			'kurstag' => $kurs->kurstagSatz($this->jetzt()),
			'zeilen' => $this->zeilen($kurs),
			'betreff' => $betreff,
			'textAngemeldete' => $textAngemeldete,
			'textWartende' => $textWartende,
			'fehler' => $fehler,
		]);
	}

	/**
	 * Anmeldung und Warteliste mit ihrer Zahl. Nur diese beiden bekommen
	 * eine Nachricht.
	 *
	 * @return list<array{beschriftung: string, eintraege: int}>
	 */
	private function zeilen(Kurs $kurs): array {
		$zeilen = [];
		foreach ($kurs->zeilen() as $zeile) {
			if ($zeile->formular === $kurs->anmeldung || $zeile->formular === $kurs->warteliste) {
				$zeilen[] = ['beschriftung' => $zeile->beschriftung, 'eintraege' => $zeile->formular->abgaben];
			}
		}
		return $zeilen;
	}

	private function ohneRecht(): TemplateResponse {
		return $this->meldung('Dafür fehlt die Berechtigung',
			'Nachrichten verschicken darf nur, wer in der dafür eingetragenen '
			. 'Nextcloud-Gruppe steht.');
	}

	private function meldung(string $titel, string $meldung): TemplateResponse {
		return new TemplateResponse($this->appName, 'meldung', [
			'pageTitle' => $titel,
			'titel' => $titel,
			'meldung' => $meldung,
			'vorformatiert' => false,
		]);
	}

	private function feld(string $name): string {
		return trim((string)$this->request->getParam($name, ''));
	}

	private function jetzt(): DateTimeImmutable {
		return $this->timeFactory->now()->setTimezone($this->zeitzone->desVereins());
	}
}
