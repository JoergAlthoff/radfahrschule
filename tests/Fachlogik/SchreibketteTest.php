<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\AnlegenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Eingabe;
use OCA\Radfahrschule\Fachlogik\Schreibkette;
use OCA\Radfahrschule\Fachlogik\Vorschau;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

final class SchreibketteTest extends TestCase {
	use MitTitelmuster;

	private const GRUPPE = 'Radfahrschule';

	/**
	 * Die beiden Vorlagen, wie sie in Nextcloud stehen. Der
	 * Wartelisten-Link in der Beschreibung der Anmeldung und die
	 * Terminzeile im Bedingungstext sind das, was die Kette ersetzt - ohne
	 * sie prueft der Test die Haelfte nicht.
	 */
	private function doppelMitVorlagen(): FormulareDoppel {
		$bedingungstext = "## Ort und Zeiten\n- **Termin:** {{termin}}\n";

		$anmeldung = new Formular(
			id: 23, hash: 'editor0000000023',
			titel: 'VORLAGE Anmeldung - Anfängerkurs',
			beschreibung: 'Ausgebucht? Dann hier vormerken: '
				. 'https://cloud.example.org/apps/forms/s/PLATZHALTERWARTELISTE',
			abgaben: 0, ablauf: 0,
			fragen: [new Frage(30, 'teilnahmebedingungen', 'Bedingungen', $bedingungstext)],
		);
		$warteliste = new Formular(
			id: 24, hash: 'editor0000000024',
			titel: 'VORLAGE Warteliste - Anfängerkurs',
			beschreibung: 'Warteliste zum Kurs.',
			abgaben: 0, ablauf: 0,
			fragen: [new Frage(40, 'teilnahmebedingungen', 'Bedingungen', $bedingungstext)],
		);

		return new FormulareDoppel([$warteliste, $anmeldung]);
	}

	private function vorschau(): Vorschau {
		$zone = new DateTimeZone('UTC');
		return Vorschau::rechne(
			new Eingabe(
				vorlageAnmeldung: 23, vorlageWarteliste: 24,
				von: new DateTimeImmutable('2026-10-10', $zone),
				bis: new DateTimeImmutable('2026-10-11', $zone),
				anmeldeschluss: new DateTimeImmutable('2026-10-03', $zone),
				plaetze: 6,
			),
			new DateTimeImmutable('2026-09-01 10:00:00', $zone), $this->titelmuster(),
			$this->ablauf());
	}

	private function kette(FormulareDoppel $doppel): Schreibkette {
		return new Schreibkette($doppel, $this->vorschau(), self::GRUPPE);
	}

	public function testEinVollstaendigerLaufLegtBeideFormulareAn(): void {
		$doppel = $this->doppelMitVorlagen();

		$ergebnis = $this->kette($doppel)->fuehreAus();

		$this->assertGreaterThan(0, $ergebnis->anmeldungId);
		$this->assertGreaterThan(0, $ergebnis->wartelisteId);
		$this->assertNotSame($ergebnis->anmeldungId, $ergebnis->wartelisteId);
	}

	/**
	 * Die Warteliste kommt zuerst: Die Beschreibung der Anmeldung zeigt auf
	 * ihren Freigabe-Link. Umgekehrt zeigte der Link auf die Warteliste des
	 * Vorgaengerkurses.
	 */
	public function testDieWartelisteEntstehtVorDerAnmeldung(): void {
		$doppel = $this->doppelMitVorlagen();

		$this->kette($doppel)->fuehreAus();

		$erstesKlonen = array_search('formularKlonen:24', $doppel->aufrufe, true);
		$zweitesKlonen = array_search('formularKlonen:23', $doppel->aufrufe, true);
		$this->assertLessThan($zweitesKlonen, $erstesKlonen);
	}

	/**
	 * Freigegeben wird erst, wenn der Inhalt stimmt. Sonst stuende
	 * mittendrin ein oeffentlich anmeldbares Formular mit dem Termin des
	 * Vorgaengerkurses.
	 *
	 * Ausnahme ist die Link-Freigabe der Warteliste: Ihr Hash entsteht erst
	 * dadurch und wird in Schritt 8 gebraucht.
	 */
	public function testDieFreigabenDerAnmeldungStehenHinterDerKontrolle(): void {
		$doppel = $this->doppelMitVorlagen();

		$ergebnis = $this->kette($doppel)->fuehreAus();

		$kontrolle = array_search(
			'formularHolen:' . $ergebnis->anmeldungId, $doppel->aufrufe, true);
		$freigabe = array_search(
			'linkFreigabeAnlegen:' . $ergebnis->anmeldungId, $doppel->aufrufe, true);
		$this->assertLessThan($freigabe, $kontrolle);
	}

	public function testBeideFormulareTragenIhrenTitel(): void {
		$doppel = $this->doppelMitVorlagen();

		$ergebnis = $this->kette($doppel)->fuehreAus();

		$this->assertSame(
			'Radfahrschule Musterstadt — Anfängerkurs 10./11.10.2026',
			$doppel->formularHolen($ergebnis->anmeldungId)->titel,
		);
		$this->assertSame(
			'Warteliste — Anfängerkurs 10./11.10.2026',
			$doppel->formularHolen($ergebnis->wartelisteId)->titel,
		);
	}

	/**
	 * Der Wartelisten-Link der Anmeldung zeigt auf die NEUE Warteliste. Ohne
	 * diesen Test schickte das neue Formular alle Ausgebuchten auf die
	 * Warteliste des Vorgaengerkurses - der haeufigste Fehler des Handwegs.
	 */
	public function testDerWartelistenLinkZeigtAufDieNeueWarteliste(): void {
		$doppel = $this->doppelMitVorlagen();

		$ergebnis = $this->kette($doppel)->fuehreAus();
		$beschreibung = $doppel->formularHolen($ergebnis->anmeldungId)->beschreibung;

		$this->assertStringContainsString($ergebnis->wartelisteHash, $beschreibung);
		$this->assertStringNotContainsString('PLATZHALTERWARTELISTE', $beschreibung);
	}

	/**
	 * Die Terminzeile wird in BEIDEN Formularen ersetzt. Die Warteliste
	 * traegt dieselben Teilnahmebedingungen; wird sie dort vergessen, steht
	 * der Termin des Vorgaengerkurses darin - an der Instanz schon gesehen.
	 */
	public function testDerTerminWirdInBeidenFormularenErsetzt(): void {
		$doppel = $this->doppelMitVorlagen();

		$ergebnis = $this->kette($doppel)->fuehreAus();

		$this->assertContains(
			'frageAendern:' . $ergebnis->wartelisteId . '/40', $doppel->aufrufe);
		$this->assertContains(
			'frageAendern:' . $ergebnis->anmeldungId . '/30', $doppel->aufrufe);
	}

	/**
	 * Die Platzzahl gehoert an die Anmeldung, und die Warteliste bekommt
	 * ausdruecklich KEINE: Nextcloud klont maxSubmissions mit, und eine
	 * versehentlich gewaehlte Anmeldung braechte sonst still ein Limit mit.
	 */
	public function testDieWartelisteBekommtKeinPlatzlimit(): void {
		$doppel = $this->doppelMitVorlagen();

		$ergebnis = $this->kette($doppel)->fuehreAus();

		$anDerWarteliste = $doppel->zuletztGeaendert($ergebnis->wartelisteId);
		$this->assertArrayHasKey('maxSubmissions', $anDerWarteliste);
		$this->assertNull($anDerWarteliste['maxSubmissions']);

		$anDerAnmeldung = $doppel->zuletztGeaendert($ergebnis->anmeldungId);
		$this->assertSame(6, $anDerAnmeldung['maxSubmissions']);
	}

	/**
	 * showExpiration muss ausdruecklich gesetzt werden: Der Klon setzt es
	 * auf false zurueck. Ohne diese Zeile stuende der Anmeldeschluss
	 * nirgends im Formular, obwohl er wirkt.
	 */
	public function testBeideFormulareZeigenIhrenAblauf(): void {
		$doppel = $this->doppelMitVorlagen();

		$ergebnis = $this->kette($doppel)->fuehreAus();

		$this->assertTrue($doppel->zuletztGeaendert($ergebnis->wartelisteId)['showExpiration']);
		$this->assertTrue($doppel->zuletztGeaendert($ergebnis->anmeldungId)['showExpiration']);
	}

	/**
	 * Jeder Schritt kann scheitern, und jeder muss seine Nummer nennen. Ohne
	 * die Nummer sagt die Meldung nicht, wie weit der Lauf kam - und genau
	 * das entscheidet, was danach noch in Nextcloud steht.
	 */
	public function testEinGescheiterterSchrittNenntSeineNummer(): void {
		$doppel = $this->doppelMitVorlagen();
		$doppel->laessScheitern('formularKlonen:23');

		try {
			$this->kette($doppel)->fuehreAus();
			$this->fail('Es haette scheitern muessen.');
		} catch (AnlegenFehlgeschlagen $fehler) {
			$this->assertSame(6, $fehler->schritt);
			$this->assertStringContainsString('Schritt 6 von 13', $fehler->getMessage());
		}
	}

	/**
	 * Was der Lauf angelegt hat, muss er auch benennen koennen - sonst kann
	 * das Zurueckrollen es nicht wegraeumen.
	 */
	public function testDerLaufWeissWasErAngelegtHat(): void {
		$doppel = $this->doppelMitVorlagen();
		$doppel->laessScheitern('formularKlonen:23');
		$kette = $this->kette($doppel);

		try {
			$kette->fuehreAus();
		} catch (AnlegenFehlgeschlagen) {
			// erwartet
		}

		$erzeugte = $kette->erzeugte();
		$this->assertCount(1, $erzeugte);
		$this->assertSame('Warteliste', $erzeugte[0]->beschriftung);
	}

	/**
	 * keyValuePairs prueft keine Schluesselnamen: Ein Tippfehler antwortet
	 * mit 200 und aendert nichts. Ohne Zuruecklesen saehe ein wirkungsloser
	 * Lauf wie ein Erfolg aus.
	 */
	public function testEinWirkungslosesSchreibenFaelltInSchrittZehnAuf(): void {
		$doppel = $this->doppelMitVorlagen();
		$doppel->laessAendernWirkungslos();

		try {
			$this->kette($doppel)->fuehreAus();
			$this->fail('Es haette scheitern muessen.');
		} catch (AnlegenFehlgeschlagen $fehler) {
			$this->assertSame(10, $fehler->schritt);
		}
	}

	/**
	 * Auch die WARTELISTE muss zurueckgelesen werden.
	 *
	 * Schritt 10 sah nur die Anmeldung an. Verpuffte der PATCH auf die
	 * Warteliste, behielt sie den Titel "VORLAGE … - Kopie", den Ablauf 0
	 * und den Termin des Vorgaengerkurses - wurde in Schritt 5 trotzdem
	 * freigegeben und in Schritt 8 verlinkt. Titel::zerlege stuft sie wegen
	 * des VORLAGE-Praefixes als Vorlage ein, sie faellt aus der Kursliste,
	 * und die Uebersicht zeigt einen halben Kurs.
	 *
	 * Stumm ist hier nur die Warteliste: Der erste Klon bekommt im Doppel
	 * die id 100, und geklont wird sie zuerst. Der alte Schalter schaltete
	 * alle zugleich stumm - damit flog immer schon die Anmeldung auf.
	 */
	public function testAuchDieWartelisteWirdZurueckgelesen(): void {
		$doppel = $this->doppelMitVorlagen();
		$doppel->laessAendernWirkungslosBei(100);

		try {
			$this->kette($doppel)->fuehreAus();
			$this->fail('Es haette scheitern muessen.');
		} catch (AnlegenFehlgeschlagen $fehler) {
			$this->assertSame(10, $fehler->schritt);
			$this->assertStringContainsString('Warteliste', $fehler->getMessage());
		}
	}

	/**
	 * Die Terminzeile ist ein EIGENER Schreibaufruf (frageAendern) und
	 * braucht deshalb eine eigene Kontrolle. Der Titel deckt sie nicht mit
	 * ab - er kommt aus formularAendern.
	 */
	public function testEineVerpuffteTerminzeileFaelltAuf(): void {
		$doppel = $this->doppelMitVorlagen();
		$doppel->laessFrageVerschwinden();

		try {
			$this->kette($doppel)->fuehreAus();
			$this->fail('Es haette scheitern muessen.');
		} catch (AnlegenFehlgeschlagen $fehler) {
			$this->assertSame(10, $fehler->schritt);
			$this->assertStringContainsString('Termin', $fehler->getMessage());
		}
	}

	/**
	 * Fehlt die Frage, muss es laut scheitern. Sonst traegt der neue Kurs
	 * still den Termin des alten.
	 */
	public function testOhneBedingungsfrageScheitertEs(): void {
		$ohneFragen = new FormulareDoppel([
			new Formular(id: 24, hash: 'editor0000000024',
				titel: 'VORLAGE Warteliste - Anfängerkurs', beschreibung: '',
				abgaben: 0, ablauf: 0, fragen: []),
			new Formular(id: 23, hash: 'editor0000000023',
				titel: 'VORLAGE Anmeldung - Anfängerkurs',
				beschreibung: '/apps/forms/s/PLATZHALTERWARTELISTE',
				abgaben: 0, ablauf: 0, fragen: []),
		]);

		$this->expectException(AnlegenFehlgeschlagen::class);
		$this->kette($ohneFragen)->fuehreAus();
	}
}
