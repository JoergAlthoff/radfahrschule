<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use Throwable;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Formulare;

/**
 * Die Schreibkette: alles, was in Nextcloud etwas anlegt oder aendert, in
 * fester Reihenfolge.
 *
 * Lesende Aufrufe wie die Kennungspruefung gehoeren nicht dazu und bekommen
 * deshalb keine Schrittnummer.
 *
 * Die Aufteilung folgt der einen Regel, die die Reihenfolge traegt: Ein
 * Formular wird erst freigegeben, wenn sein Inhalt stimmt. Deshalb steht
 * schalteFrei hinter leseZurueck und nicht mittendrin.
 */
final class Schreibkette {
	/** Der technische Schluessel der Frage, die den Kurstermin traegt. */
	private const BEDINGUNGSFRAGE = 'teilnahmebedingungen';

	/** Die Zahl der Aufrufe eines vollstaendigen Laufs. Steht in der Meldung. */
	private const SCHRITTE_INSGESAMT = 13;

	private int $anmeldungId = 0;
	private int $wartelisteId = 0;
	private string $anmeldungHash = '';
	private string $wartelisteHash = '';

	/** @var list<ErzeugtesFormular> */
	private array $erzeugt = [];

	/**
	 * Ein Klon-Aufruf ging hinaus, und seine Antwort kam nicht an.
	 *
	 * Die id eines Klons kennt der Aufrufer erst AUS der Antwort. Bleibt sie
	 * aus, laesst sich nicht sagen, ob in Nextcloud ein Formular entstanden
	 * ist - und erst recht nicht, welches. Zurueckrollen kann es also nicht
	 * wegraeumen, und die Meldung darf nicht behaupten, es sei nichts
	 * entstanden.
	 */
	private bool $klonUngewiss = false;

	public function __construct(
		private readonly Formulare $formulare,
		private readonly Vorschau $vorschau,
		private readonly string $gruppe,
	) {
	}

	/** @throws AnlegenFehlgeschlagen */
	public function fuehreAus(): Ergebnis {
		$this->baueWarteliste();
		$this->baueAnmeldung();
		$this->leseZurueck();
		$this->schalteFrei();

		return new Ergebnis(
			anmeldungId: $this->anmeldungId,
			wartelisteId: $this->wartelisteId,
			anmeldungHash: $this->anmeldungHash,
			wartelisteHash: $this->wartelisteHash,
		);
	}

	/**
	 * Was dieser Lauf angelegt hat - und nur das darf zurueckgerollt werden.
	 *
	 * Gefuellt wird die Liste an genau zwei Stellen, jeweils direkt nach
	 * einem Klon. Nie aus einer Formularliste: Eine ID, die von woanders
	 * herkommt, koennte einen laufenden Kurs treffen.
	 *
	 * @return list<ErzeugtesFormular>
	 */
	public function erzeugte(): array {
		return $this->erzeugt;
	}

	/** Siehe das Feld: Ob ein Klon entstanden ist, ist offen. */
	public function klonIstUngewiss(): bool {
		return $this->klonUngewiss;
	}

	/** Schritte 1 bis 5. */
	private function baueWarteliste(): void {
		$klon = $this->klone(1, $this->vorschau->eingabe->vorlageWarteliste);
		$this->wartelisteId = $klon->id;
		$this->erzeugt[] = new ErzeugtesFormular('Warteliste', $klon->id, $klon->hash);

		// maxSubmissions ausdruecklich auf null: Nextcloud klont das
		// Platzlimit mit. Eine versehentlich gewaehlte Anmeldung braechte
		// sonst still ein Limit mit. showExpiration setzt der Klon auf false
		// zurueck.
		$this->schritt(2, fn () => $this->formulare->formularAendern($klon->id, [
			'title' => $this->vorschau->titelWarteliste,
			'expires' => $this->vorschau->ablaufWarteliste,
			'showExpiration' => true,
			'maxSubmissions' => null,
		]));

		// Schritt 3 und 4: Die Warteliste traegt DIESELBEN
		// Teilnahmebedingungen wie die Anmeldung, also auch dieselbe
		// Terminzeile. Wird sie hier nicht ersetzt, steht dort der Termin
		// des Vorgaengerkurses.
		$this->ersetzeTerminIn($klon->id, 3, 4);

		// Schritt 5: Die Warteliste ist jetzt inhaltlich fertig. Erst
		// deshalb darf sie aufgeschlossen werden.
		//
		// Ihre Freigabe muss HIER stehen und nicht bei den anderen am Ende:
		// Der Link entsteht erst durch sie, und Schritt 8 schreibt ihn in
		// die Beschreibung der Anmeldung.
		$this->wartelisteHash = $this->schritt(5, fn () => $this->formulare
			->linkFreigabeAnlegen($klon->id));
	}

	/**
	 * Schritte 6 bis 9.
	 *
	 * Keine Freigabe darin: Die Anmeldung wird erst aufgeschlossen, wenn
	 * Schritt 10 bestaetigt hat, dass ihr Inhalt stimmt.
	 */
	private function baueAnmeldung(): void {
		$klon = $this->klone(6, $this->vorschau->eingabe->vorlageAnmeldung);
		$this->anmeldungId = $klon->id;
		$this->erzeugt[] = new ErzeugtesFormular('Anmeldung', $klon->id, $klon->hash);

		// Schritt 7: Gelesen wird, weil der Klon in der Beschreibung noch
		// den Hash der ALTEN Warteliste traegt und im Bedingungstext den
		// Termin des Vorgaengerkurses.
		$gelesen = $this->schritt(7, fn () => $this->formulare->formularHolen($klon->id));

		$neueBeschreibung = $this->schritt(8, fn () => Textersetzung::wartelistenLink(
			$gelesen->beschreibung, $this->wartelisteHash));

		$this->schritt(8, fn () => $this->formulare->formularAendern($klon->id, [
			'title' => $this->vorschau->titelAnmeldung,
			'description' => $neueBeschreibung,
			'expires' => $this->vorschau->ablaufAnmeldung,
			'showExpiration' => true,
			'maxSubmissions' => $this->vorschau->plaetze,
		]));

		$frage = $gelesen->frageMitNamen(self::BEDINGUNGSFRAGE);
		if ($frage === null) {
			throw new AnlegenFehlgeschlagen(9, self::SCHRITTE_INSGESAMT, sprintf(
				'Die Frage „%s" gibt es im Formular nicht.', self::BEDINGUNGSFRAGE));
		}

		$neuerText = $this->schritt(9, fn () => Textersetzung::terminZeile(
			$frage->beschreibung, $this->vorschau->termin));

		$this->schritt(9, fn () => $this->formulare->frageAendern(
			$klon->id, $frage->id, ['description' => $neuerText]));
	}

	/**
	 * Klont eine Vorlage und haelt fest, wenn die Antwort ausbleibt.
	 *
	 * Der Merker steht VOR dem Aufruf und faellt erst nach der Rueckkehr
	 * wieder. Dazwischen ist offen, ob in Nextcloud ein Formular entstanden
	 * ist - genau die Frage, die die Meldung danach beantworten muss.
	 *
	 * @throws AnlegenFehlgeschlagen
	 */
	private function klone(int $nummer, int $vorlageId): Formular {
		$this->klonUngewiss = true;
		$klon = $this->schritt($nummer,
			fn () => $this->formulare->formularKlonen($vorlageId));
		$this->klonUngewiss = false;

		return $klon;
	}

	/**
	 * Schritt 10 - Pflicht, kein Luxus.
	 *
	 * keyValuePairs prueft keine Schluesselnamen: Ein Tippfehler im
	 * Feldnamen antwortet mit 200 und aendert nichts. Ohne Zuruecklesen
	 * saehe ein wirkungsloser Lauf wie ein Erfolg aus.
	 *
	 * Er muss VOR den Freigaben stehen. Danach waere die Anmeldung laengst
	 * oeffentlich, und ein Fehlschlag hinterliesse ein anmeldbares Formular
	 * mit dem Termin des Vorgaengerkurses.
	 *
	 * BEIDE Formulare werden angesehen. Die Warteliste ist zu diesem
	 * Zeitpunkt zwar schon freigegeben - ihr Link entsteht in Schritt 5 und
	 * wird in Schritt 8 gebraucht -, aber er steht nur in der Beschreibung
	 * einer Anmeldung, die selbst noch verschlossen ist. Faellt hier etwas
	 * auf, raeumt das Zurueckrollen beide Klone weg.
	 *
	 * Vorher wurde nur die Anmeldung geprueft. Verpuffte der PATCH auf die
	 * Warteliste, blieb sie eine "VORLAGE … - Kopie" - und faellt als
	 * Vorlage aus der Kursliste. Die Uebersicht zeigte danach einen halben
	 * Kurs, waehrend das Protokoll Erfolg meldete.
	 */
	private function leseZurueck(): void {
		$anmeldung = $this->schritt(10, fn () => $this->formulare
			->formularHolen($this->anmeldungId));

		$this->pruefeTitel($anmeldung, $this->vorschau->titelAnmeldung);

		if (!str_contains($anmeldung->beschreibung, $this->wartelisteHash)) {
			throw new AnlegenFehlgeschlagen(10, self::SCHRITTE_INSGESAMT,
				'Der Wartelisten-Link steht nicht in der Beschreibung.');
		}

		$this->pruefeTerminzeile($anmeldung, 'Anmeldung');

		$warteliste = $this->schritt(10, fn () => $this->formulare
			->formularHolen($this->wartelisteId));

		$this->pruefeTitel($warteliste, $this->vorschau->titelWarteliste);
		$this->pruefeTerminzeile($warteliste, 'Warteliste');
	}

	/**
	 * Der Titel steht fuer den ganzen Aufruf: Titel, Ablauf und Platzzahl
	 * gehen in EINEM formularAendern hinaus. Kam der Titel an, kam der Rest
	 * mit - eine eigene Pruefung des Ablaufs braucht es deshalb nicht.
	 */
	private function pruefeTitel(Formular $formular, string $erwartet): void {
		if ($formular->titel !== $erwartet) {
			throw new AnlegenFehlgeschlagen(10, self::SCHRITTE_INSGESAMT, sprintf(
				'Der Titel steht auf „%s" statt auf „%s" — hat das Schreiben gewirkt?',
				$formular->titel, $erwartet));
		}
	}

	/**
	 * Die Terminzeile kommt aus einem EIGENEN Aufruf (frageAendern) und
	 * braucht deshalb eine eigene Kontrolle. Steht dort noch der Termin des
	 * Vorgaengerkurses, liest sich der Kurs richtig und ist es nicht.
	 */
	private function pruefeTerminzeile(Formular $formular, string $beschriftung): void {
		$frage = $formular->frageMitNamen(self::BEDINGUNGSFRAGE);
		if ($frage === null || !str_contains($frage->beschreibung, $this->vorschau->termin)) {
			throw new AnlegenFehlgeschlagen(10, self::SCHRITTE_INSGESAMT, sprintf(
				'Im Formular „%s" steht der Termin %s nicht im Bedingungstext.',
				$beschriftung, $this->vorschau->termin));
		}
	}

	/**
	 * Schritte 11 bis 13.
	 *
	 * Alle Freigaben, die nicht fuer den Aufbau selbst gebraucht werden,
	 * stehen hier - hinter der Kontrolle in Schritt 10. Was nicht geprueft
	 * ist, wird nicht aufgeschlossen; niemand soll ueber einen Kurs
	 * benachrichtigt werden, der noch nicht steht.
	 */
	private function schalteFrei(): void {
		// Schritt 11: Nextcloud klont Freigaben nicht mit - der Klon kommt
		// mit einer leeren Liste zurueck.
		//
		// Der Hash kommt aus DIESER Antwort und nicht aus dem Klon.
		// Letzterer ist der Hash der Editor-Adresse, 16 Zeichen statt 24;
		// als oeffentlicher Link fuehrt er ins Leere.
		$this->anmeldungHash = $this->schritt(11, fn () => $this->formulare
			->linkFreigabeAnlegen($this->anmeldungId));

		// Schritt 12 und 13: Ohne die Gruppe sieht niemand ausser dem
		// Eigentuemerkonto die Formulare, und niemand bekaeme eine Meldung
		// ueber eine neue Anmeldung.
		$this->schritt(12, fn () => $this->formulare->gruppenFreigabeAnlegen(
			$this->wartelisteId, $this->gruppe));
		$this->schritt(13, fn () => $this->formulare->gruppenFreigabeAnlegen(
			$this->anmeldungId, $this->gruppe));
	}

	/**
	 * Liest den Bedingungstext eines Formulars und schreibt den neuen Termin
	 * hinein. Zwei Aufrufe, deshalb zwei Schrittnummern.
	 *
	 * Die Anmeldung geht diesen Weg nicht: Sie liest ohnehin schon, weil
	 * auch der Wartelisten-Link zu ersetzen ist, und spart sich damit einen
	 * Aufruf.
	 */
	private function ersetzeTerminIn(int $formularId, int $leseschritt, int $schreibschritt): void {
		$gelesen = $this->schritt($leseschritt, fn () => $this->formulare
			->formularHolen($formularId));

		$frage = $gelesen->frageMitNamen(self::BEDINGUNGSFRAGE);
		if ($frage === null) {
			throw new AnlegenFehlgeschlagen($schreibschritt, self::SCHRITTE_INSGESAMT,
				sprintf('Die Frage „%s" gibt es im Formular nicht.', self::BEDINGUNGSFRAGE));
		}

		$neuerText = $this->schritt($schreibschritt, fn () => Textersetzung::terminZeile(
			$frage->beschreibung, $this->vorschau->termin));

		$this->schritt($schreibschritt, fn () => $this->formulare->frageAendern(
			$formularId, $frage->id, ['description' => $neuerText]));
	}

	/**
	 * Fuehrt einen Schritt aus und benennt ihn, wenn er scheitert.
	 *
	 * @template T
	 * @param callable():T $handlung
	 * @return T
	 * @throws AnlegenFehlgeschlagen
	 */
	private function schritt(int $nummer, callable $handlung): mixed {
		try {
			return $handlung();
		} catch (AnlegenFehlgeschlagen $fehler) {
			// Schon benannt - nicht doppelt einpacken, sonst stuende zweimal
			// "Schritt N von 13" in derselben Meldung.
			throw $fehler;
		} catch (Throwable $fehler) {
			throw new AnlegenFehlgeschlagen(
				$nummer, self::SCHRITTE_INSGESAMT, $fehler->getMessage(), $fehler);
		}
	}
}
