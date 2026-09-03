<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Verschiebekette;
use OCA\Radfahrschule\Fachlogik\VerschiebenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Verschiebeplan;
use OCA\Radfahrschule\Fachlogik\Verschiebung;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

final class VerschiebeketteTest extends TestCase {
	use MitTitelmuster;

	private const BEDINGUNGSTEXT = "## Ort und Zeiten\n- **Termin:** 12./13.09.2026\n";

	private function doppel(?string $bedingungstext = null): FormulareDoppel {
		$text = $bedingungstext ?? self::BEDINGUNGSTEXT;
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 1_000_000,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', $text)]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 2_000_000,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', $text)]),
		]);
	}

	private function kurs(FormulareDoppel $doppel): Kurs {
		return Kursliste::ausFormularen($doppel->alleEigenen(), $this->titelmuster())[0];
	}

	private function plan(Kurs $kurs): Verschiebeplan {
		$zone = new DateTimeZone('UTC');
		return Verschiebeplan::rechne(
			$kurs,
			new Verschiebung(
				von: new DateTimeImmutable('2026-11-14', $zone),
				bis: new DateTimeImmutable('2026-11-15', $zone),
				anmeldeschluss: new DateTimeImmutable('2026-11-07', $zone),
			),
			new DateTimeImmutable('2026-09-01 10:00:00', $zone), $this->titelmuster(),
			$this->ablauf());
	}

	private function kette(FormulareDoppel $doppel): Verschiebekette {
		$kurs = $this->kurs($doppel);
		return new Verschiebekette($doppel, $this->plan($kurs), $kurs, $this->titelmuster());
	}

	/**
	 * Ein Formular, dessen Titel keine Art nennt, darf nicht mitverschoben
	 * werden - es wuerde zur Anmeldung umgetauft.
	 *
	 * neuerTitelFuer kannte nur "Warteliste oder sonst Anmeldung". Ein
	 * Formular ohne Praefix faellt trotzdem in den Kurs (der ganze Titel ist
	 * dann die Kennung) und bekam damit den Anmeldungstitel samt
	 * Anmeldeschluss. Ergebnis: zwei Formulare mit identischem Titel,
	 * Kurs::anmeldungen() zaehlt nur das erste, und eine Warteliste mit
	 * echten Eintraegen sieht aus wie eine Anmeldung.
	 *
	 * Abgewiesen wird beim EINLESEN, nicht beim Schreiben: Danach stuende
	 * der halbe Kurs schon unter neuem Namen da.
	 */
	public function testEinFormularOhneErkennbareArtWirdAbgewiesen(): void {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 1_000_000,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 2_000_000,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
		]);
		$kette = $this->kette($doppel);

		try {
			$kette->leseAllesEin();
			$this->fail('Es haette VerschiebenFehlgeschlagen kommen muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('Anfängerkurs 12./13.09.2026',
				$fehler->getMessage());
			$this->assertFalse($fehler->schonGeschrieben,
				'Beim Einlesen ist in Nextcloud noch nichts geschehen.');
		}

		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(19)->titel);
		$this->assertSame('Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(18)->titel);
	}

	/**
	 * Zurueckgelesen wird JEDES Formular, nicht nur das erste.
	 *
	 * Geprueft wurde nur gelesen[0] - die Anmeldung. Nahm Forms den PATCH
	 * auf die Warteliste mit 200 an und aenderte nichts, meldete die Seite
	 * "Kurs verschoben": Die Anmeldung trug den neuen Titel, die Warteliste
	 * den alten. Das Paar ist damit zerrissen, die Uebersicht zeigt zwei
	 * halbe Kurse, und die Warteliste laeuft am alten Kurstag ab.
	 *
	 * Der alte Schalter laessAendernWirkungslos schaltete alle Formulare
	 * zugleich stumm - damit flog immer schon das erste auf.
	 */
	public function testJedesFormularWirdZurueckgelesen(): void {
		$doppel = $this->doppel();
		$doppel->laessAendernWirkungslosBei(18);

		$kette = $this->kette($doppel);
		$kette->leseAllesEin();

		try {
			$kette->schreibeAlles();
			$this->fail('Es haette VerschiebenFehlgeschlagen kommen muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('Warteliste', $fehler->getMessage());
			$this->assertTrue($fehler->schonGeschrieben);
		}
	}

	/**
	 * Ein Schreibaufruf, der Forms erreicht hat, dessen Antwort aber
	 * verloren ging, muss trotzdem zurueckgeschrieben werden.
	 *
	 * Der Vermerk stand NACH dem Aufruf. Reisst die Antwort ab oder greift
	 * das 30-Sekunden-Timeout, blieb die Liste leer - und das
	 * Zurueckschreiben liess genau das Formular aus, das gerade geaendert
	 * wurde. Ein Formular doppelt zurueckzuschreiben ist dagegen harmlos:
	 * Es bekommt denselben alten Stand.
	 */
	public function testEinAbgerissenerAufrufGiltAlsGeschrieben(): void {
		$doppel = $this->doppel();
		$doppel->laessNachDerWirkungScheitern('formularAendern:18');

		$kette = $this->kette($doppel);
		$kette->leseAllesEin();

		try {
			$kette->schreibeAlles();
			$this->fail('Es haette VerschiebenFehlgeschlagen kommen muessen.');
		} catch (VerschiebenFehlgeschlagen) {
			// erwartet
		}

		$geaenderte = $kette->geaenderte();
		$this->assertCount(1, $geaenderte);
		$this->assertSame(18, $geaenderte[0]->id);
	}

	public function testBeideFormulareBekommenDenNeuenTitel(): void {
		$doppel = $this->doppel();
		$kette = $this->kette($doppel);

		$kette->leseAllesEin();
		$kette->schreibeAlles();

		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 14./15.11.2026',
			$doppel->formularHolen(19)->titel);
		$this->assertSame('Warteliste — Anfängerkurs 14./15.11.2026',
			$doppel->formularHolen(18)->titel);
	}

	/**
	 * Die beiden laufen VERSCHIEDEN aus: die Anmeldung zum Anmeldeschluss,
	 * die Warteliste erst am Kurstag. Eine gemeinsame Zeit machte die
	 * Warteliste nutzlos - sie soll gerade dann noch offen sein, wenn die
	 * Anmeldung schon zu ist.
	 */
	public function testJedesFormularBekommtSeinenEigenenAblauf(): void {
		$doppel = $this->doppel();
		$kette = $this->kette($doppel);

		$kette->leseAllesEin();
		$kette->schreibeAlles();

		$anmeldung = $doppel->zuletztGeaendert(19);
		$warteliste = $doppel->zuletztGeaendert(18);
		$this->assertGreaterThan($anmeldung['expires'], $warteliste['expires']);
	}

	public function testDieTerminzeileWirdInBeidenFormularenErsetzt(): void {
		$doppel = $this->doppel();
		$kette = $this->kette($doppel);

		$kette->leseAllesEin();
		$kette->schreibeAlles();

		$this->assertContains('frageAendern:19/30', $doppel->aufrufe);
		$this->assertContains('frageAendern:18/40', $doppel->aufrufe);
	}

	/**
	 * Hier faellt auf, wenn die Terminzeile fehlt - und zwar BEVOR irgendwo
	 * geschrieben wurde. Eine Meldung nach dem ersten Schreibaufruf waere zu
	 * spaet: Der Kurs stuende dann unter neuem Namen mit altem Text da.
	 */
	public function testEineFehlendeTerminzeileFaelltVorDemSchreibenAuf(): void {
		$doppel = $this->doppel("Kein Termin weit und breit.\n");
		$kette = $this->kette($doppel);

		try {
			$kette->leseAllesEin();
			$this->fail('Es haette scheitern muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('Termin', $fehler->getMessage());
			$this->assertFalse($fehler->schonGeschrieben);
		}

		// Nichts geschrieben - kein einziger aendernder Aufruf.
		$this->assertSame([], array_values(array_filter(
			$doppel->aufrufe,
			static fn (string $aufruf): bool => str_starts_with($aufruf, 'formularAendern')
				|| str_starts_with($aufruf, 'frageAendern'),
		)));
	}

	public function testEineFehlendeBedingungsfrageFaelltEbenfallsFrueherAuf(): void {
		$ohneFragen = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0, fragen: []),
		]);
		$kurs = $this->kurs($ohneFragen);
		$kette = new Verschiebekette($ohneFragen, $this->plan($kurs), $kurs, $this->titelmuster());

		$this->expectException(VerschiebenFehlgeschlagen::class);
		$kette->leseAllesEin();
	}

	/**
	 * Die Gesamtzahl wird GERECHNET: Je Formular fallen zwei Schreibaufrufe
	 * an. Bei einem Kurs aus einem einzelnen Formular ergaebe eine feste
	 * Zahl "Schritt 2 von 4" - in einem Text, den jemand liest.
	 */
	public function testDieSchrittzahlRichtetSichNachDerZahlDerFormulare(): void {
		$doppel = $this->doppel();
		$doppel->laessScheitern('formularAendern:18');
		$kette = $this->kette($doppel);
		$kette->leseAllesEin();

		try {
			$kette->schreibeAlles();
			$this->fail('Es haette scheitern muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertSame(4, $fehler->schritteInsgesamt);
			$this->assertSame(1, substr_count($fehler->getMessage(), 'Schritt 1 von 4'));
		}
	}

	/**
	 * In der Ruecknahmeliste steht nur, was der Lauf wirklich angefasst hat.
	 *
	 * Ob ein einzelner Wert wirklich geschrieben wurde, entscheidet nicht
	 * mehr diese Liste, sondern Zurueckschreiben am Ist-Stand: Ein Merker
	 * konnte nicht unterscheiden, ob ein Aufruf abgelehnt wurde oder nur
	 * seine Antwort verloren ging.
	 */
	public function testNurAngefasstesStehtInDerRuecknahmeliste(): void {
		$doppel = $this->doppel();
		$doppel->laessScheitern('frageAendern:18/40');
		$kette = $this->kette($doppel);
		$kette->leseAllesEin();

		try {
			$kette->schreibeAlles();
		} catch (VerschiebenFehlgeschlagen) {
			// erwartet
		}

		$geaenderte = $kette->geaenderte();
		$this->assertCount(1, $geaenderte, 'Formular 19 kam nicht mehr an die Reihe.');
		$this->assertSame(18, $geaenderte[0]->id);
	}

	/**
	 * keyValuePairs prueft keine Schluesselnamen: Ein Tippfehler antwortet
	 * mit 200 und aendert nichts. Ohne Zuruecklesen saehe ein wirkungsloser
	 * Lauf wie ein Erfolg aus.
	 */
	public function testEinWirkungslosesSchreibenFaelltBeimZuruecklesenAuf(): void {
		$doppel = $this->doppel();
		$doppel->laessAendernWirkungslos();
		$kette = $this->kette($doppel);
		$kette->leseAllesEin();

		$this->expectException(VerschiebenFehlgeschlagen::class);
		$kette->schreibeAlles();
	}

	/**
	 * Beim Zuruecklesen stehen zwei Bedingungen nebeneinander: dass die
	 * Bedingungsfrage ueberhaupt noch da ist ODER dass der neue Termin
	 * darin steht. Der Test darueber deckt nur die zweite ab - er laesst
	 * die Frage stehen und nur den Text alt.
	 *
	 * Ohne diesen Test hier liesse sich das ODER in ein UND verwandeln,
	 * ohne dass etwas rot wird.
	 */
	public function testEineVerschwundeneBedingungsfrageFaelltBeimZuruecklesenAuf(): void {
		$doppel = $this->doppel();
		$doppel->laessFrageVerschwinden();
		$kette = $this->kette($doppel);
		$kette->leseAllesEin();

		$this->expectException(VerschiebenFehlgeschlagen::class);
		$kette->schreibeAlles();
	}
}
