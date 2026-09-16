<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Radfahrschule\Benachrichtigung\Nachricht;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Fachlogik\Benachrichtigen;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\KursGibtEsSchon;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\KursNichtGefunden;
use OCA\Radfahrschule\Fachlogik\Kursverschieben;
use OCA\Radfahrschule\Fachlogik\Termin;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\VerschiebenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Verschiebeplan;
use OCA\Radfahrschule\Fachlogik\Verschiebung;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Empfaenger;
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
		private readonly Benachrichtigen $benachrichtigen,
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
		// /kurs/{id} des KursController. Welche von beiden gewinnt, haengt
		// von der Reihenfolge ab, in der Nextcloud die Controller-Dateien
		// einliest - und die kommt aus dem Dateisystem, nicht aus einer
		// Sortierung (DirectoryIterator in Router::getAttributeRoutes).
		// Sie ist damit je Instanz anders. Eine GET-Route gehoert deshalb
		// nicht unter /kurs/; eine POST-Route kollidiert nicht.
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
			// Kommt die Seite von der Kontrollseite zurueck, reist das
			// Getippte als versteckte Felder weiter.
			'mitgebracht' => $this->mitgebrachteNachricht(),
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

		return $this->kontrollseite($kurs, $verschiebung, $this->vorbelegteNachricht(), '', '');
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

		$nachricht = [
			'betreff' => $this->feld('betreff'),
			'textAngemeldete' => $this->feld('textAngemeldete'),
			'textWartende' => $this->feld('textWartende'),
		];
		$einTextSteht = $nachricht['textAngemeldete'] !== '' || $nachricht['textWartende'] !== '';

		// Der Grund wird nur geprueft, wenn ein Text dasteht. Ohne Text
		// braucht das Verschieben keinen Aufruf mehr als ohne Nachricht.
		$grundOhneNachricht = '';
		if ($einTextSteht) {
			try {
				$grundOhneNachricht = $this->grundOhneNachricht($kurs);
			} catch (FormulareNichtErreichbar $fehler) {
				return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
			}
		}
		$nachrichtGewollt = $einTextSteht && $grundOhneNachricht === '';

		if ($nachrichtGewollt && $nachricht['betreff'] === '') {
			return $this->kontrollseite($kurs, $verschiebung, $nachricht, '',
				'Es ist nichts verschoben. Für die Nachricht fehlt der Betreff. Wer keine '
				. 'Nachricht schicken will, leert beide Texte.');
		}

		// Die Adressen VOR dem Verschieben. Antwortet Forms hier nicht, ist
		// noch nichts geaendert.
		$auftraege = [];
		if ($nachrichtGewollt) {
			try {
				$auftraege = $this->auftraegeZumNeuenTermin($kurs, $verschiebung, $nachricht);
			} catch (FormulareNichtErreichbar $fehler) {
				return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
			}
		}

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
			return $this->kontrollseite($kurs, $verschiebung, $nachricht,
				'Gerade legt oder verschiebt jemand anderes einen Kurs. Es wurde '
				. 'nichts geändert; ein Klick auf „Jetzt verschieben" nimmt die '
				. 'Angaben mit.', '');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			return $this->verschiebenGescheitert($fehler, $nachrichtGewollt);
		}

		$ergebnis = null;
		if ($nachrichtGewollt) {
			$ergebnis = $this->benachrichtigen->schicke(
				$kurs, $auftraege, $this->recht->benutzer(), $this->jetzt());
		}

		// Ging an die Angemeldeten keine Nachricht hinaus - weil ueberhaupt
		// keine gewollt war oder ihr Text leer blieb -, sollen sie nicht in
		// Vergessenheit geraten.
		$angemeldeteOhneNachricht = !$nachrichtGewollt || $nachricht['textAngemeldete'] === '';
		$erinnerung = $kurs->anmeldungen() > 0 && $angemeldeteOhneNachricht;

		return new TemplateResponse($this->appName, 'verschoben', [
			'pageTitle' => 'Kurs verschoben',
			'neueKennung' => $plan->neueKennung,
			'anmeldungen' => $kurs->anmeldungen(),
			'terminportalHinweis' => $this->betreiberangaben->terminportalHinweis(),
			// Leer, wenn keine Nachricht gewollt war.
			'nachricht' => $ergebnis?->satz() ?? '',
			'gescheitert' => $ergebnis->gescheitert ?? [],
			'erinnerung' => $erinnerung,
		]);
	}

	/**
	 * Liest die Adressen und baut die Nachricht je Liste. Verschickt nichts.
	 *
	 * {termin} ist der bisherige Termin, {neuer_termin} der neue.
	 *
	 * @param array{betreff: string, textAngemeldete: string, textWartende: string} $nachricht
	 * @return list<array{empfaenger: Empfaenger, nachricht: Nachricht}>
	 * @throws FormulareNichtErreichbar
	 */
	private function auftraegeZumNeuenTermin(Kurs $kurs, Verschiebung $verschiebung, array $nachricht): array {
		$neuerTermin = Termin::kurz($verschiebung->von, $verschiebung->bis);
		$anAngemeldete = $this->benachrichtigen->nachricht(
			$kurs, $nachricht['betreff'], $nachricht['textAngemeldete'], $neuerTermin);
		$anWartende = $this->benachrichtigen->nachricht(
			$kurs, $nachricht['betreff'], $nachricht['textWartende'], $neuerTermin);
		return $this->benachrichtigen->auftraege($kurs, $anAngemeldete, $anWartende);
	}

	/** Die Meldung, wenn das Verschieben scheitert. Eine Nachricht geht dann nicht hinaus. */
	private function verschiebenGescheitert(
		VerschiebenFehlgeschlagen $fehler,
		bool $nachrichtGewollt,
	): TemplateResponse {
		$ohneNachricht = $nachrichtGewollt
			? "\n\nEs ist keine Nachricht zum neuen Termin hinausgegangen."
			: '';

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

			return $this->meldung('Das Verschieben ging nicht', $text . $ohneNachricht,
				vorformatiert: true);
		}

		return $this->meldung('Das Verschieben brach ab', $fehler->getMessage() . $ohneNachricht,
			vorformatiert: true);
	}

	/**
	 * Die Kontrollseite, mit den Feldern der Nachricht zum neuen Termin.
	 *
	 * Sie kommt beim ersten Aufruf, bei belegter Sperre und bei einem Text
	 * ohne Betreff. Jedes Mal mit dem, was schon getippt ist.
	 *
	 * @param array{betreff: string, textAngemeldete: string, textWartende: string} $nachricht
	 */
	private function kontrollseite(
		Kurs $kurs,
		Verschiebung $verschiebung,
		array $nachricht,
		string $hinweis,
		string $fehlerZurNachricht,
	): TemplateResponse {
		try {
			$plan = Verschiebeplan::rechne($kurs, $verschiebung, $this->jetzt(), $this->titelmuster, $this->ablauf);
		} catch (InvalidArgumentException $fehler) {
			return $this->meldung('Die Angaben passen nicht', $fehler->getMessage());
		}

		try {
			$grundOhneNachricht = $this->grundOhneNachricht($kurs);
		} catch (FormulareNichtErreichbar $fehler) {
			return $this->meldung('Kurs nicht abrufbar', $fehler->getMessage());
		}

		// Bleibt der Termin gleich und kommt nichts Getipptes mit, aendert
		// sich nur der Anmeldeschluss. Eine Vorbelegung aus den Einstellungen
		// waere dann die Nachricht "ist verschoben" zu einem Termin, der
		// gleich bleibt.
		$nurFristGeaendert = $plan->neueKennung === $plan->alteKennung
			&& $this->mitgebrachteNachricht() === null;
		if ($nurFristGeaendert) {
			$nachricht = ['betreff' => '', 'textAngemeldete' => '', 'textWartende' => ''];
		}

		$angaben = $this->angabenAus($plan, $kurs, $hinweis);
		$angaben['betreff'] = $nachricht['betreff'];
		$angaben['textAngemeldete'] = $nachricht['textAngemeldete'];
		$angaben['textWartende'] = $nachricht['textWartende'];
		$angaben['fehler'] = $fehlerZurNachricht;
		$angaben['grundOhneNachricht'] = $grundOhneNachricht;
		$angaben['nurFristGeaendert'] = $nurFristGeaendert;

		return new TemplateResponse($this->appName, 'verschiebevorschau', $angaben);
	}

	/**
	 * Warum keine Nachricht moeglich ist, oder ''.
	 *
	 * Verschoben werden kann trotzdem. Ein Kurs darf nicht am alten Termin
	 * haengen bleiben, weil der Mailversand fehlt.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	private function grundOhneNachricht(Kurs $kurs): string {
		if ($this->benachrichtigen->versandIstAbgeschaltet()) {
			return 'Auf dieser Nextcloud ist der Mailversand abgeschaltet. Der Kurs '
				. 'lässt sich trotzdem verschieben, nur ohne Nachricht.';
		}
		$fehlendeAngabe = $this->benachrichtigen->fehlendeAngabe($kurs);
		if ($fehlendeAngabe === null) {
			return '';
		}
		return $fehlendeAngabe . ' Der Kurs lässt sich trotzdem verschieben, nur ohne Nachricht.';
	}

	/**
	 * Das Mitgebrachte, sonst die Vorbelegung aus den Einstellungen.
	 *
	 * @return array{betreff: string, textAngemeldete: string, textWartende: string}
	 */
	private function vorbelegteNachricht(): array {
		$mitgebracht = $this->mitgebrachteNachricht();
		if ($mitgebracht !== null) {
			return $mitgebracht;
		}
		return [
			'betreff' => $this->betreiberangaben->verschiebungBetreff(),
			'textAngemeldete' => $this->betreiberangaben->verschiebungTextAngemeldete(),
			'textWartende' => $this->betreiberangaben->verschiebungTextWartende(),
		];
	}

	/**
	 * Betreff und Texte, wenn sie im Request stehen, sonst null.
	 *
	 * Der Weg zurueck zum Formular und wieder vor traegt das Getippte als
	 * versteckte Felder. Ein geleertes Feld ist dabei gewollt und bleibt
	 * leer. Fehlt der Betreff ganz, kommt die Seite von der Kursseite.
	 *
	 * @return array{betreff: string, textAngemeldete: string, textWartende: string}|null
	 */
	private function mitgebrachteNachricht(): ?array {
		if ($this->request->getParam('betreff') === null) {
			return null;
		}
		return [
			'betreff' => $this->feld('betreff'),
			'textAngemeldete' => $this->feld('textAngemeldete'),
			'textWartende' => $this->feld('textWartende'),
		];
	}

	private function feld(string $name): string {
		return trim((string)$this->request->getParam($name, ''));
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
