<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use DateTimeImmutable;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Nachruecken;
use OCA\Radfahrschule\Fachlogik\Nachrueckergebnis;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Abgabe;
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
 * Wartende in die Anmeldung nachruecken lassen.
 *
 * Seite und Ausfuehren sind POST. Die Kennung traegt Schraegstriche und
 * passt in keinen Pfad, und eine GET-Route mit Platzhalter koennte mit einer
 * anderen kollidieren. Die Ergebnisseite ist GET und kommt ohne Platzhalter
 * aus, ihre Angaben liegen in der Sitzung.
 */
class NachrueckenController extends Controller {
	/** Unter diesem Schluessel wartet das Ergebnis in der Sitzung auf seine Seite. */
	private const SITZUNG_ERGEBNIS = 'radfahrschule_nachrueckergebnis';

	private const TAGESFORMAT = 'd.m.Y';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Formulare $formulare,
		private readonly Nachruecken $nachruecken,
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

	/** Die Seite mit der Warteliste. Sie liest nur. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/nachruecken')]
	public function seite(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$geprueft = $this->kursOderMeldung();
		if ($geprueft instanceof TemplateResponse) {
			return $geprueft;
		}

		return $this->seiteFuer($geprueft, [], '');
	}

	/**
	 * Das Nachruecken.
	 *
	 * Alle Pruefungen laufen hier ein zweites Mal. Auf der Kursseite fehlt
	 * der Knopf ohne Recht, aber den POST kann jeder von Hand schicken.
	 *
	 * Danach leitet die App um. Liesse sie die Ergebnisseite als Antwort auf
	 * den POST stehen, schickte ein Neuladen die Auswahl noch einmal ab.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/nachruecken/ausfuehren')]
	public function ausfuehren(): TemplateResponse|RedirectResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$geprueft = $this->kursOderMeldung();
		if ($geprueft instanceof TemplateResponse) {
			return $geprueft;
		}
		$kurs = $geprueft;
		$auswahl = $this->auswahl();

		try {
			$ergebnis = $this->nachruecken->lasseNachruecken(
				$kurs, $auswahl, $this->recht->benutzer(), $this->jetzt());
		} catch (GeradeBeschaeftigt) {
			return $this->meldung('Gerade beschäftigt',
				'Es läuft gerade ein anderer Vorgang. Bitte in ein paar '
				. 'Sekunden noch einmal versuchen.');
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Nachrücken nicht möglich',
				'Es ist niemand nachgerückt. ' . $fehler->getMessage());
		}

		if ($ergebnis->einwand !== null) {
			return $this->seiteFuer($kurs, $auswahl, $ergebnis->einwand);
		}

		$this->legeErgebnisAb($kurs, $ergebnis);

		$ergebnisadresse = $this->urlGenerator->linkToRoute('radfahrschule.nachruecken.ergebnis');
		return new RedirectResponse($ergebnisadresse);
	}

	/**
	 * Die Ergebnisseite.
	 *
	 * Das Ergebnis wird beim Anzeigen aus der Sitzung genommen. Wer die Seite
	 * danach neu laedt, landet auf der Uebersicht.
	 *
	 * Ohne CSRF-Pruefung, weil eine Umleitung keinen Token mitbringt. Die
	 * Seite aendert nichts, sie zeigt nur, was in der eigenen Sitzung liegt.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/nachgerueckt')]
	public function ergebnis(): TemplateResponse|RedirectResponse {
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

		return new TemplateResponse($this->appName, 'nachgerueckt', [
			'pageTitle' => 'Nachgerückt',
			'kennung' => $ergebnis['kennung'],
			'id' => $ergebnis['id'],
			'satz' => $ergebnis['satz'],
			'nachgerueckt' => $ergebnis['nachgerueckt'],
			'stoerung' => $ergebnis['stoerung'],
			'uebrig' => $ergebnis['uebrig'],
		]);
	}

	private function legeErgebnisAb(Kurs $kurs, Nachrueckergebnis $ergebnis): void {
		$this->session->set(self::SITZUNG_ERGEBNIS, [
			'kennung' => $kurs->kennung,
			'id' => $kurs->eineId(),
			'satz' => $ergebnis->satz(),
			'nachgerueckt' => $ergebnis->nachgerueckt,
			'stoerung' => $ergebnis->stoerung,
			'uebrig' => $ergebnis->uebrig,
		]);
	}

	/**
	 * Recht, Kennung, Kurs - in dieser Reihenfolge.
	 *
	 * Gibt den Kurs zurueck oder die Seite, die sagt, woran es liegt.
	 */
	private function kursOderMeldung(): Kurs|TemplateResponse {
		if (!$this->recht->darfVerwalten()) {
			return $this->ohneRecht();
		}

		$kennung = trim((string)$this->request->getParam('kennung', ''));
		if ($kennung === '') {
			return $this->meldung('Die Angaben passen nicht',
				'Es kam kein Kurs mit. Bitte von der Übersicht neu beginnen.');
		}

		try {
			$kurs = $this->kursMitKennung($kennung);
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		if ($kurs === null) {
			return $this->meldung('Unbekannter Kurs', 'Zu dieser Kennung gibt es keinen Kurs.');
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

	/**
	 * Die Seite mit Plaetzen und Warteliste.
	 *
	 * @param list<int> $angekreuzt die Nummern, deren Haken gesetzt bleiben
	 */
	private function seiteFuer(Kurs $kurs, array $angekreuzt, string $fehler): TemplateResponse {
		try {
			$anmeldung = $this->nachruecken->anmeldung($kurs);
			$wartende = $this->nachruecken->wartende($kurs);
		} catch (FormulareNichtErreichbar $ursache) {
			return $this->meldung('Nachrücken nicht möglich', $ursache->getMessage());
		}

		$hindernis = $this->nachruecken->hindernis($anmeldung, $this->jetzt()) ?? '';
		// Derselbe Satz steht nur einmal auf der Seite, als Hindernis.
		if ($fehler === $hindernis) {
			$fehler = '';
		}

		return new TemplateResponse($this->appName, 'nachruecken', [
			'pageTitle' => 'Nachrücken lassen',
			'kennung' => $kurs->kennung,
			'id' => $kurs->eineId(),
			'platzsatz' => $this->nachruecken->platzsatz($anmeldung),
			'hindernis' => $hindernis,
			'fehler' => $fehler,
			'zeilen' => $this->zeilen($wartende, $angekreuzt),
		]);
	}

	/**
	 * Je Wartendem: Nummer, Name, Tag des Eintrags. Mehr zeigt die Seite
	 * nicht.
	 *
	 * @param list<Abgabe> $wartende
	 * @param list<int> $angekreuzt
	 * @return list<array{nummer: int, name: string, eingetragen: string, angekreuzt: bool}>
	 */
	private function zeilen(array $wartende, array $angekreuzt): array {
		$zeilen = [];
		foreach ($wartende as $abgabe) {
			$name = $abgabe->name();
			$zeilen[] = [
				'nummer' => $abgabe->id,
				'name' => $name === '' ? '(ohne Namen)' : $name,
				'eingetragen' => $this->tag($abgabe->zeitpunkt),
				'angekreuzt' => in_array($abgabe->id, $angekreuzt, true),
			];
		}
		return $zeilen;
	}

	/** Der Kalendertag eines Eintrags, abgelesen dort, wo der Verein sitzt. */
	private function tag(int $zeitpunkt): string {
		$weltzeit = new DateTimeImmutable('@' . $zeitpunkt);
		$ortszeit = $weltzeit->setTimezone($this->zeitzone->desVereins());
		return $ortszeit->format(self::TAGESFORMAT);
	}

	/**
	 * Die angekreuzten Nummern aus dem POST.
	 *
	 * Was keine Liste aus Ziffernfolgen ist, zaehlt nicht. Ob eine Nummer
	 * wirklich auf der Warteliste steht, prueft die Fachlogik.
	 *
	 * @return list<int>
	 */
	private function auswahl(): array {
		$roh = $this->request->getParam('abgaben', []);
		if (!is_array($roh)) {
			return [];
		}

		$nummern = [];
		foreach ($roh as $wert) {
			if (is_string($wert) && ctype_digit($wert)) {
				$nummern[] = (int)$wert;
			}
		}
		return $nummern;
	}

	private function ohneRecht(): TemplateResponse {
		return $this->meldung('Dafür fehlt die Berechtigung',
			'Nachrücken lassen darf nur, wer in der dafür eingetragenen '
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

	private function jetzt(): DateTimeImmutable {
		return $this->timeFactory->now()->setTimezone($this->zeitzone->desVereins());
	}
}
