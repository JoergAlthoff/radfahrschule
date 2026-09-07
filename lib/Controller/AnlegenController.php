<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\AnlegenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Eingabe;
use OCA\Radfahrschule\Fachlogik\Ergebnis;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kursanlegen;
use OCA\Radfahrschule\Fachlogik\KursGibtEsSchon;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Vorlagenauswahl;
use OCA\Radfahrschule\Fachlogik\Vorlagenpaar;
use OCA\Radfahrschule\Fachlogik\Vorschau;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
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

class AnlegenController extends Controller {
	/** Das Format, das <input type="date"> sendet und erwartet. */
	private const DATUMSFORMAT = 'Y-m-d';

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Formulare $formulare,
		private readonly Kursanlegen $kursanlegen,
		private readonly Verwaltungsrecht $recht,
		private readonly Zugangsdaten $zugangsdaten,
		private readonly ITimeFactory $timeFactory,
		private readonly INavigationManager $navigationManager,
		private readonly Titelmuster $titelmuster,
		private readonly Betreiberangaben $betreiberangaben,
		private readonly Ablauf $ablauf,
		private readonly Zeitzone $zeitzone,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/neuer-kurs')]
	public function formular(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Kurse anlegen darf nur, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht.');
		}

		try {
			$paar = Vorlagenpaar::ausFormularen($this->formulare->alleEigenen());
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Vorlagen nicht abrufbar', $fehler->getMessage());
		}

		$fehlende = $paar->fehlendeArten();
		if ($fehlende !== []) {
			return $this->meldung('Vorlage fehlt', sprintf(
				'Ohne Vorlage lässt sich kein Kurs anlegen. In Nextcloud steht kein '
				. 'Formular, dessen Titel so beginnt: „%s"',
				implode('", „', $fehlende)));
		}

		return new TemplateResponse($this->appName, 'anlegeformular', [
			'pageTitle' => 'Neuen Kurs anlegen',
			'vorlagenAnmeldung' => $paar->anmeldung->optionen(),
			'vorlagenWarteliste' => $paar->warteliste->optionen(),
			'freieWahlAnmeldung' => $paar->anmeldung->freieWahl(),
			'freieWahlWarteliste' => $paar->warteliste->freieWahl(),
			// Der Weg zurueck von der Kontrollseite bringt sie mit.
			'vonIso' => $this->vorbelegung('von'),
			'bisIso' => $this->vorbelegung('bis'),
			'anmeldeschlussIso' => $this->vorbelegung('anmeldeschluss'),
			'plaetze' => $this->vorbelegung('plaetze', '6'),
		]);
	}

	/**
	 * Der Wert fuer ein Formularfeld: das Eingetippte, sonst die Vorgabe.
	 *
	 * Die Kontrollseite haengt die Werte an ihren Zurueck-Link. Ohne sie
	 * stuende das Formular wieder leer da, und wer sich in einem von vier
	 * Feldern vertippt hat, faengt bei allen vieren von vorn an.
	 *
	 * Die zwei Vorlagenfelder bleiben aussen vor: Bei genau einer Vorlage je
	 * Art ist sie ohnehin vorgewaehlt, und mehr gibt es hier nicht.
	 */
	private function vorbelegung(string $feld, string $vorgabe = ''): string {
		$eingetippt = trim((string)$this->request->getParam($feld, ''));

		return $eingetippt !== '' ? $eingetippt : $vorgabe;
	}

	/**
	 * Die Kontrollseite. Sie rechnet nur - Nextcloud wird dabei einzig nach
	 * der Vorlagenliste gefragt.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/vorschau')]
	public function vorschau(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$angenommen = $this->nimmEingabeAn();
		if ($angenommen instanceof TemplateResponse) {
			return $angenommen;
		}

		try {
			$vorschau = Vorschau::rechne($angenommen, $this->jetzt(), $this->titelmuster, $this->ablauf);
		} catch (InvalidArgumentException $fehler) {
			return $this->meldung('Die Angaben passen nicht', $fehler->getMessage());
		}

		return new TemplateResponse($this->appName, 'vorschau',
			$this->angabenAus($vorschau, 'So würde der Kurs aussehen'));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/anlegen')]
	public function ausfuehren(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$angenommen = $this->nimmEingabeAn();
		if ($angenommen instanceof TemplateResponse) {
			return $angenommen;
		}

		try {
			$ergebnis = $this->kursanlegen->legeAn(
				$angenommen, $this->recht->benutzer(), $this->jetzt());
		} catch (InvalidArgumentException $fehler) {
			return $this->meldung('Die Angaben passen nicht', $fehler->getMessage());
		} catch (FormulareNichtErreichbar $fehler) {
			// legeAn fragt Forms ein ZWEITES Mal, um die Kennung zu pruefen -
			// nimmEingabeAn deckt nur den ersten Aufruf ab. Faellt Forms
			// dazwischen aus, gaebe es ohne diesen Zweig eine leere
			// Fehlerseite statt einer Meldung.
			return $this->meldung('Vorlagen nicht abrufbar', $fehler->getMessage());
		} catch (KursGibtEsSchon $fehler) {
			// Eigene Ueberschrift: Hier wurde NICHTS geschrieben. Unter "Das
			// Anlegen brach ab" suchte jemand nach Ueberresten, die es nicht
			// gibt.
			return $this->meldung('Diesen Kurs gibt es schon', $fehler->getMessage());
		} catch (GeradeBeschaeftigt) {
			// Die Angaben kommen zurueck auf die Seite statt in eine
			// Sackgasse: Ein zweiter Versuch gelingt, und getippt wurde
			// nichts falsch.
			return $this->zeigeBeschaeftigt($angenommen);
		} catch (AnlegenFehlgeschlagen $fehler) {
			return $this->meldung('Das Anlegen brach ab', $fehler->getMessage(),
				vorformatiert: true);
		}

		return $this->zeigeErfolg($angenommen, $ergebnis);
	}

	/**
	 * Was beide POST-Wege gemeinsam haben: Recht pruefen, Formular lesen,
	 * die gewaehlten Vorlagen gegen ihre eigene Auswahl halten.
	 *
	 * Bei einer TemplateResponse ist die Antwort schon fertig.
	 */
	private function nimmEingabeAn(): Eingabe|TemplateResponse {
		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Kurse anlegen darf nur, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht.');
		}

		try {
			$eingabe = $this->eingabeAusDemFormular();
		} catch (InvalidArgumentException $fehler) {
			return $this->meldung('Die Angaben passen nicht', $fehler->getMessage());
		}

		try {
			$paar = Vorlagenpaar::ausFormularen($this->formulare->alleEigenen());
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Vorlagen nicht abrufbar', $fehler->getMessage());
		}

		// Je Feld gegen SEINE Auswahl. Wer beide gegen dieselbe Liste haelt,
		// laesst die Vertauschung von Anmeldung und Warteliste durch.
		if (!$paar->anmeldung->enthaelt($eingabe->vorlageAnmeldung)
			|| !$paar->warteliste->enthaelt($eingabe->vorlageWarteliste)) {
			return $this->meldung('Vorlage nicht zulässig', sprintf(
				'Im Feld für die Anmeldung muss eine Vorlage stehen, deren Titel mit '
				. '„%s" beginnt, im Feld für die Warteliste eine mit „%s". '
				. 'Bitte von vorn beginnen.',
				Vorlagenauswahl::PRAEFIX_ANMELDUNG, Vorlagenauswahl::PRAEFIX_WARTELISTE));
		}

		return $eingabe;
	}

	/**
	 * Liest die Felder des Formulars.
	 *
	 * Jeder Lesefehler wird gemeldet und nicht verschluckt: Ein unlesbares
	 * Datum gaebe sonst irgendeinen Tag, und daraus entstuende ein Kurs zu
	 * einem Termin, den niemand eingegeben hat.
	 */
	private function eingabeAusDemFormular(): Eingabe {
		return new Eingabe(
			vorlageAnmeldung: $this->zahl('vorlage_anmeldung', 'Vorlage der Anmeldung'),
			vorlageWarteliste: $this->zahl('vorlage_warteliste', 'Vorlage der Warteliste'),
			von: $this->datum('von', 'Erster Kurstag'),
			bis: $this->datum('bis', 'Letzter Kurstag'),
			anmeldeschluss: $this->datum('anmeldeschluss', 'Anmeldeschluss'),
			plaetze: $this->zahl('plaetze', 'Platzzahl'),
		);
	}

	/**
	 * Der senkrechte Strich im Format setzt die uebrigen Felder auf null.
	 * Ohne ihn uebernaehme createFromFormat die aktuelle Uhrzeit, und zwei
	 * gleiche Kalendertage waeren nicht mehr gleich.
	 */
	private function datum(string $feld, string $beschriftung): DateTimeImmutable {
		$roh = (string)$this->request->getParam($feld, '');
		$datum = DateTimeImmutable::createFromFormat(
			self::DATUMSFORMAT . '|', $roh, Zeitzone::zumAblegen());

		// Auf false allein ist kein Verlass: createFromFormat rollt einen
		// unmoeglichen Kalendertag still weiter. Aus "2026-06-31" wird der
		// 01.07.2026, aus "2026-13-45" der 14.02.2027 - nie false.
		//
		// getLastErrors() liefert genau dann ein Array, wenn beim Lesen etwas
		// nicht stimmte; sonst false. Gemessen am Mac: sauberer Tag false,
		// weitergerollter Tag eine Warnung, Anhaengsel hinter dem Datum ein
		// Fehler. Alle drei Faelle sind hier unerwuenscht.
		$sauberGelesen = DateTimeImmutable::getLastErrors() === false;

		if ($datum === false || !$sauberGelesen) {
			throw new InvalidArgumentException(sprintf(
				'%s: „%s" ist kein Datum.', $beschriftung, $roh));
		}
		return $datum;
	}

	private function zahl(string $feld, string $beschriftung): int {
		$roh = (string)$this->request->getParam($feld, '');
		if (!ctype_digit($roh)) {
			throw new InvalidArgumentException(sprintf(
				'%s: „%s" ist keine Zahl.', $beschriftung, $roh));
		}
		return (int)$roh;
	}

	private function zeigeBeschaeftigt(Eingabe $eingabe): TemplateResponse {
		try {
			$vorschau = Vorschau::rechne($eingabe, $this->jetzt(), $this->titelmuster, $this->ablauf);
		} catch (InvalidArgumentException $fehler) {
			// Kann nicht eintreten - legeAn hat schon gerechnet. Ein "kann
			// nicht" ist trotzdem kein Grund, einen Fehler fallen zu lassen.
			return $this->meldung('Gerade beschäftigt', $fehler->getMessage());
		}

		return new TemplateResponse($this->appName, 'beschaeftigt',
			$this->angabenAus($vorschau, 'Gerade beschäftigt'));
	}

	private function zeigeErfolg(Eingabe $eingabe, Ergebnis $ergebnis): TemplateResponse {
		try {
			$vorschau = Vorschau::rechne($eingabe, $this->jetzt(), $this->titelmuster, $this->ablauf);
		} catch (InvalidArgumentException $fehler) {
			// Sollte nicht eintreten - legeAn hat schon gerechnet. Es KANN
			// aber: Faellt Mitternacht zwischen die Pruefung und diese Zeile,
			// liegt der letzte Kurstag ploetzlich in der Vergangenheit.
			//
			// Hier waeren die Folgen schwerer als bei zeigeBeschaeftigt:
			// Beide Formulare stehen schon in Nextcloud, und diese Seite ist
			// die einzige, die beide oeffentlichen Links NEBENEINANDER zeigt.
			// Wiederfinden liesse sie die Kursseite zwar (zeilenMitLink holt
			// sie je Formular nach), aber nur ueber einen Umweg.
			return $this->meldung('Kurs angelegt, Anzeige unvollständig',
				"Der Kurs wurde angelegt. Die Übersicht zeigt ihn.\n\n"
				. 'Die Erfolgsseite ließ sich nicht aufbauen: '
				. $fehler->getMessage(), vorformatiert: true);
		}

		return new TemplateResponse($this->appName, 'erfolg', [
			'pageTitle' => 'Kurs angelegt',
			'titelAnmeldung' => $vorschau->titelAnmeldung,
			'titelWarteliste' => $vorschau->titelWarteliste,
			'anmeldungLink' => $this->freigabeLink($ergebnis->anmeldungHash),
			'wartelisteLink' => $this->freigabeLink($ergebnis->wartelisteHash),

			// Der Satz gehoert dem Betreiber, nicht dem Template. Ein
			// fester nennte den Verein, dem diese App gerade gehoert.
			'terminportalHinweis' => $this->betreiberangaben->terminportalHinweis(),
		]);
	}

	/** @return array<string, mixed> */
	private function angabenAus(Vorschau $vorschau, string $seitentitel): array {
		return [
			'pageTitle' => $seitentitel,
			'titelAnmeldung' => $vorschau->titelAnmeldung,
			'titelWarteliste' => $vorschau->titelWarteliste,
			'termin' => $vorschau->termin,
			'plaetze' => $vorschau->plaetze,
			// Getrennt: Ein <input type="date"> verlangt ISO, ein Mensch
			// liest 10.10.2026. Teilten sie sich ein Feld, gewaenne ISO.
			'vonIso' => $vorschau->vonIso(),
			'bisIso' => $vorschau->bisIso(),
			'anmeldeschlussIso' => $vorschau->anmeldeschlussIso(),
			'anmeldeschlussLesbar' => $vorschau->eingabe->anmeldeschluss->format('d.m.Y'),
			'vorlageAnmeldung' => $vorschau->eingabe->vorlageAnmeldung,
			'vorlageWarteliste' => $vorschau->eingabe->vorlageWarteliste,
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
			// Die Meldung eines abgebrochenen Laufs nennt in eigenen Zeilen,
			// was stehengeblieben ist. In einem <p> fraesse HTML die
			// Umbrueche.
			'vorformatiert' => $vorformatiert,
		]);
	}

	private function freigabeLink(string $hash): string {
		return rtrim($this->zugangsdaten->basisUrl(), '/') . '/apps/forms/s/' . $hash;
	}

	/**
	 * In die Zeitzone des Vereins gedreht. Erst sie entscheidet, welcher
	 * Kalendertag "heute" ist - now() liefert UTC, und dort ist zwischen
	 * Mitternacht und zwei Uhr noch der Vortag.
	 */
	private function jetzt(): DateTimeImmutable {
		return $this->timeFactory->now()->setTimezone($this->zeitzone->desVereins());
	}
}
