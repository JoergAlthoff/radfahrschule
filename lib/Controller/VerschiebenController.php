<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\KursGibtEsSchon;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\KursNichtGefunden;
use OCA\Radfahrschule\Fachlogik\Kursverschieben;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\VerschiebenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Verschiebeplan;
use OCA\Radfahrschule\Fachlogik\Verschiebung;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\INavigationManager;
use OCP\IRequest;

class VerschiebenController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Formulare $formulare,
		private readonly Kursverschieben $kursverschieben,
		private readonly Verwaltungsrecht $recht,
		private readonly ITimeFactory $timeFactory,
		private readonly INavigationManager $navigationManager,
		private readonly Titelmuster $titelmuster,
		private readonly Betreiberangaben $betreiberangaben,
		private readonly Ablauf $ablauf,
		private readonly Zeitzone $zeitzone,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Das Formular ist mit dem BISHERIGEN Termin vorbelegt.
	 *
	 * Verschoben wird meist um wenige Tage; wer bei null anfangen muss,
	 * tippt das Jahr neu - und genau dort passiert der Fehler, den die
	 * Kontrollseite abfangen soll.
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/verschieben/formular')]
	public function formular(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Kurse verschieben darf nur, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht.');
		}

		// Die Kennung kommt im POST-Koerper, nicht im Pfad. Zwei Gruende:
		//
		// Sie traegt Schraegstriche ("24./25.07.2027"), und ein
		// Routen-Platzhalter matcht genau ein Segment - linkToRoute lehnt
		// eine solche Adresse ab.
		//
		// Und eine GET-Route unter /kurs/ faellt in die Detailroute
		// /kurs/{id} des KursController: Der wird alphabetisch VOR
		// VerschiebenController eingelesen und gewinnt. /kurs/neu geht nur
		// deshalb, weil AnlegenController noch davor kommt - wer sich darauf
		// verlaesst, baut auf die Sortierung von Dateinamen. Eine POST-Route
		// kollidiert damit nicht.
		$kennung = (string)$this->request->getParam('kennung', '');
		if ($kennung === '') {
			return $this->meldung('Die Angaben passen nicht',
				'Es kam kein Kurs mit. Bitte von der Übersicht neu beginnen.');
		}

		try {
			$kurs = $this->kursMitKennung($kennung);
		} catch (KursNichtGefunden $fehler) {
			return $this->meldung('Unbekannter Kurs', $fehler->getMessage());
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		return new TemplateResponse($this->appName, 'verschiebeformular', [
			'pageTitle' => 'Termin verschieben',
			'id' => $kurs->eineId(),
			'kennung' => $kurs->kennung,
			'kurstag' => $kurs->kurstagSatz($this->jetzt()),
			'vonIso' => $this->vorbelegung('von', $kurs->ersterTag),
			'bisIso' => $this->vorbelegung('bis', $kurs->letzterTag),
			'anmeldeschlussIso' => $this->vorbelegung(
				'anmeldeschluss', null, $this->alterAnmeldeschluss($kurs)),
		]);
	}

	/**
	 * Der Wert fuers Datumsfeld: das Eingetippte, sonst der Stand des Kurses.
	 *
	 * Die Kontrollseite schickt beim Weg zurueck mit, was jemand eingegeben
	 * hat. Wer sie ignoriert, setzt das Formular auf den BISHERIGEN Termin
	 * zurueck - und wer sich in einem von drei Feldern vertippt hat, faengt
	 * bei allen dreien von vorn an.
	 *
	 * Der erste Aufruf kommt von der Kursseite und schickt nichts mit; dann
	 * gilt der Kursstand.
	 */
	private function vorbelegung(
		string $feld,
		?DateTimeImmutable $ausDemKurs,
		string $ersatz = '',
	): string {
		$eingetippt = trim((string)$this->request->getParam($feld, ''));
		if ($eingetippt !== '') {
			return $eingetippt;
		}

		return $ausDemKurs?->format('Y-m-d') ?? $ersatz;
	}

	/** Die Kontrollseite. Sie rechnet nur - geschrieben wird nichts. */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/verschieben/vorschau')]
	public function vorschau(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$angenommen = $this->nimmAn();
		if ($angenommen instanceof TemplateResponse) {
			return $angenommen;
		}

		[$kurs, $verschiebung] = $angenommen;

		try {
			$plan = Verschiebeplan::rechne($kurs, $verschiebung, $this->jetzt(), $this->titelmuster, $this->ablauf);
		} catch (InvalidArgumentException $fehler) {
			return $this->meldung('Die Angaben passen nicht', $fehler->getMessage());
		}

		return new TemplateResponse($this->appName, 'verschiebevorschau',
			$this->angabenAus($plan, $kurs));
	}

	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/kurs/verschieben')]
	public function ausfuehren(): TemplateResponse {
		$this->navigationManager->setActiveEntry($this->appName);

		$angenommen = $this->nimmAn();
		if ($angenommen instanceof TemplateResponse) {
			return $angenommen;
		}

		[$kurs, $verschiebung] = $angenommen;

		try {
			$plan = $this->kursverschieben->verschiebe(
				$kurs->kennung, $verschiebung, $this->recht->benutzer(), $this->jetzt());
		} catch (InvalidArgumentException $fehler) {
			return $this->meldung('Die Angaben passen nicht', $fehler->getMessage());
		} catch (KursNichtGefunden $fehler) {
			return $this->meldung('Unbekannter Kurs', $fehler->getMessage());
		} catch (FormulareNichtErreichbar $fehler) {
			// verschiebe holt den Bestand INNERHALB der Sperre noch einmal -
			// nimmAn deckt nur den Aufruf davor ab. Dieselbe Luecke wie im
			// AnlegenController.
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		} catch (KursGibtEsSchon $fehler) {
			return $this->meldung('Diesen Termin gibt es schon', $fehler->getMessage());
		} catch (GeradeBeschaeftigt) {
			return $this->zeigeBeschaeftigt($kurs, $verschiebung);
		} catch (VerschiebenFehlgeschlagen $fehler) {
			// Genau die Unterscheidung, fuer die es das Kennzeichen gibt:
			// Vor dem ersten Schreibaufruf ist in Nextcloud nichts geschehen.
			// "Das Verschieben brach ab" schickte dort jemanden nach
			// Ueberresten suchen, die es nicht gibt.
			if (!$fehler->schonGeschrieben) {
				// Der Satz kommt nur dazu, wenn die Fachlogik keinen eigenen
				// mitgibt. Nach einem gelungenen Zurueckschreiben tut sie das
				// - dann stuende hier zweimal dasselbe.
				$text = $fehler->zusatz === ''
					? $fehler->getMessage() . "\n\nIn Nextcloud wurde nichts "
						. 'geändert. Der Kurs steht unverändert da.'
					: $fehler->getMessage();

				return $this->meldung('Das Verschieben ging nicht', $text,
					vorformatiert: true);
			}

			return $this->meldung('Das Verschieben brach ab', $fehler->getMessage(),
				vorformatiert: true);
		}

		return new TemplateResponse($this->appName, 'verschoben', [
			'pageTitle' => 'Kurs verschoben',
			'neueKennung' => $plan->neueKennung,
			'anmeldungen' => $kurs->anmeldungen(),
			'terminportalHinweis' => $this->betreiberangaben->terminportalHinweis(),
		]);
	}

	/**
	 * Dieselbe Kontrollseite zurueck, diesmal mit Hinweis: Sie traegt die
	 * Felder ohnehin schon, und getippt wurde nichts falsch.
	 */
	private function zeigeBeschaeftigt(Kurs $kurs, Verschiebung $verschiebung): TemplateResponse {
		try {
			$plan = Verschiebeplan::rechne($kurs, $verschiebung, $this->jetzt(), $this->titelmuster, $this->ablauf);
		} catch (InvalidArgumentException $fehler) {
			// Kann nicht eintreten - verschiebe hat schon gerechnet. Ein
			// "kann nicht" ist trotzdem kein Grund, einen Fehler fallen zu
			// lassen.
			return $this->meldung('Gerade beschäftigt', $fehler->getMessage());
		}

		return new TemplateResponse($this->appName, 'verschiebevorschau',
			$this->angabenAus($plan, $kurs,
				'Gerade legt oder verschiebt jemand anderes einen Kurs. Es wurde '
				. 'nichts geändert; ein Klick auf „Jetzt verschieben" nimmt die '
				. 'Angaben mit.'));
	}

	/**
	 * Was beide POST-Wege gemeinsam haben: Recht pruefen, Felder lesen, den
	 * Kurs suchen.
	 *
	 * @return array{0: Kurs, 1: Verschiebung}|TemplateResponse
	 */
	private function nimmAn(): array|TemplateResponse {
		if (!$this->recht->darfVerwalten()) {
			return $this->meldung('Dafür fehlt die Berechtigung',
				'Kurse verschieben darf nur, wer in der dafür eingetragenen '
				. 'Nextcloud-Gruppe steht.');
		}

		$kennung = (string)$this->request->getParam('kennung', '');
		if ($kennung === '') {
			return $this->meldung('Die Angaben passen nicht',
				'Es kam kein Kurs mit. Bitte von der Übersicht neu beginnen.');
		}

		try {
			$verschiebung = new Verschiebung(
				von: $this->datum('von', 'Erster Kurstag'),
				bis: $this->datum('bis', 'Letzter Kurstag'),
				anmeldeschluss: $this->datum('anmeldeschluss', 'Anmeldeschluss'),
			);
		} catch (InvalidArgumentException $fehler) {
			return $this->meldung('Die Angaben passen nicht', $fehler->getMessage());
		}

		try {
			return [$this->kursMitKennung($kennung), $verschiebung];
		} catch (KursNichtGefunden $fehler) {
			return $this->meldung('Unbekannter Kurs', $fehler->getMessage());
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}
	}

	/**
	 * @throws KursNichtGefunden
	 * @throws FormulareNichtErreichbar
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
	 * Der Anmeldeschluss steht nirgends als Kalendertag: Er ist der
	 * Ablaufzeitpunkt der Anmeldung, und der traegt eine Uhrzeit. Sein Tag
	 * genuegt - in Ortszeit, weil dort gerechnet wurde.
	 *
	 * Zwei Formate, weil zwei Stellen ihn brauchen: Das Formular verlangt
	 * ISO, die Kontrollseite zeigt ihn einem Menschen. Sie stand dort
	 * zuerst als Gedankenstrich - falsch, denn der Wert ist da.
	 */
	private function alterAnmeldeschluss(Kurs $kurs, string $format = 'Y-m-d'): string {
		if ($kurs->anmeldung === null || $kurs->anmeldung->ablauf === 0) {
			return '';
		}

		return (new DateTimeImmutable('@' . $kurs->anmeldung->ablauf))
			->setTimezone($this->zeitzone->desVereins())
			->format($format);
	}

	/**
	 * Alt und neu nebeneinander - wer nur den neuen Stand sieht, kann nicht
	 * pruefen, ob er den richtigen Kurs erwischt hat.
	 *
	 * Anzeige und verstecktes Feld sind getrennt: Ein <input type="date">
	 * verlangt ISO, ein Mensch liest 15.11.2026.
	 *
	 * @return array<string, mixed>
	 */
	private function angabenAus(Verschiebeplan $plan, Kurs $kurs, string $hinweis = ''): array {
		return [
			'pageTitle' => 'So würde der Kurs verschoben',
			'hinweis' => $hinweis,
			'id' => $kurs->eineId(),
			'alteKennung' => $plan->alteKennung,
			'neueKennung' => $plan->neueKennung,
			'alterTitelAnmeldung' => $plan->alterTitelAnmeldung,
			'neuerTitelAnmeldung' => $plan->neuerTitelAnmeldung,
			'alterTitelWarteliste' => $plan->alterTitelWarteliste,
			'neuerTitelWarteliste' => $plan->neuerTitelWarteliste,
			'alterKurstag' => $kurs->kurstagDeutsch(),
			'alterAnmeldeschluss' => $this->alterAnmeldeschluss($kurs, 'd.m.Y'),
			'termin' => $plan->termin,
			'anmeldungen' => $kurs->anmeldungen(),
			'vonIso' => $plan->vonIso(),
			'bisIso' => $plan->bisIso(),
			'bisLesbar' => $plan->verschiebung->bis->format('d.m.Y'),
			'anmeldeschlussIso' => $plan->anmeldeschlussIso(),
			'anmeldeschlussLesbar' => $plan->verschiebung->anmeldeschluss->format('d.m.Y'),
		];
	}

	/**
	 * Der senkrechte Strich im Format setzt die uebrigen Felder auf null.
	 * Ohne ihn uebernaehme createFromFormat die aktuelle Uhrzeit, und zwei
	 * gleiche Kalendertage waeren nicht mehr gleich.
	 *
	 * Dieselbe Methode steht in AnlegenController. Zwei Dutzend Zeilen
	 * doppelt sind billiger als eine gemeinsame Oberklasse, die beide
	 * Controller aneinanderbindet.
	 */
	private function datum(string $feld, string $beschriftung): DateTimeImmutable {
		$roh = (string)$this->request->getParam($feld, '');
		$datum = DateTimeImmutable::createFromFormat(
			'Y-m-d|', $roh, Zeitzone::zumAblegen());

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

	private function jetzt(): DateTimeImmutable {
		return $this->timeFactory->now()->setTimezone($this->zeitzone->desVereins());
	}
}
