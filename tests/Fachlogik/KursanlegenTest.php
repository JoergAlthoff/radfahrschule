<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\AnlegenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Eingabe;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kursanlegen;
use OCA\Radfahrschule\Fachlogik\KursGibtEsSchon;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Sperre\Schreibsperre;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Protokoll\ProtokollDoppel;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class KursanlegenTest extends TestCase {
	use MitTitelmuster;

	private ProtokollDoppel $protokoll;

	protected function setUp(): void {
		$this->protokoll = new ProtokollDoppel();
	}

	/**
	 * Die Testdaten legen einen Kurs an, den es NICHT gibt. Laege der Termin
	 * auf einem Kurs des Bestandes, waere jeder Anlegetest rot, sobald die
	 * Kennungspruefung greift.
	 */
	private function eingabe(): Eingabe {
		$zone = new DateTimeZone('UTC');
		return new Eingabe(
			vorlageAnmeldung: 23, vorlageWarteliste: 24,
			von: new DateTimeImmutable('2026-10-10', $zone),
			bis: new DateTimeImmutable('2026-10-11', $zone),
			anmeldeschluss: new DateTimeImmutable('2026-10-03', $zone),
			plaetze: 6,
		);
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	private function bestand(): FormulareDoppel {
		$bedingungstext = "- **Termin:** {{termin}}\n";
		return new FormulareDoppel([
			new Formular(id: 24, hash: 'editor0000000024',
				titel: 'VORLAGE Warteliste - Anfängerkurs', beschreibung: '',
				abgaben: 0, ablauf: 0,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', $bedingungstext)]),
			new Formular(id: 23, hash: 'editor0000000023',
				titel: 'VORLAGE Anmeldung - Anfängerkurs',
				beschreibung: 'https://cloud.example.org/apps/forms/s/PLATZHALTERWARTELISTE',
				abgaben: 0, ablauf: 0,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', $bedingungstext)]),
			// Ein laufender Kurs, damit die Kennungspruefung etwas zu finden hat.
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 3, ablauf: 0),
		]);
	}

	private function anlegen(FormulareDoppel $doppel, bool $sperreFrei = true): Kursanlegen {
		$lockingProvider = $this->createStub(ILockingProvider::class);
		if (!$sperreFrei) {
			$lockingProvider->method('acquireLock')
				->willThrowException(new LockedException('belegt'));
		}

		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('freigabeGruppe')->willReturn('Radfahrschule');
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');

		return new Kursanlegen(
			$doppel, $this->protokoll, new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)), $zugangsdaten, $this->titelmuster(), $this->ablauf());
	}

	public function testEinKursEntsteht(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());

		$this->assertGreaterThan(0, $ergebnis->anmeldungId);
	}

	public function testDasProtokollHaeltFestWerAngelegtHat(): void {
		$doppel = $this->bestand();

		$this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());

		$zeile = $this->protokoll->letzter();
		$this->assertNotNull($zeile);
		$this->assertSame('kurs.angelegt', $zeile->vorgang);
		$this->assertSame('anna', $zeile->benutzer);
		$this->assertSame('2026-10-11', $zeile->kurstag);
	}

	/**
	 * Die Kennung steht OHNE Praefix im Protokoll. Das Loeschen schreibt
	 * unter demselben Namen dieselbe Schreibweise; aus dem Feld wird eine
	 * Spalte, und zwei Schreibweisen darin liessen sich nicht mehr paaren.
	 */
	public function testDieKennungStehtOhnePraefixImProtokoll(): void {
		$doppel = $this->bestand();

		$this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());

		$this->assertSame('Anfängerkurs 10./11.10.2026', $this->protokoll->letzter()->kennung);
	}

	/**
	 * Ohne diese Pruefung erzeugt ein Doppelklick zwei Kurspaare mit
	 * identischen Titeln.
	 */
	public function testEinVorhandenerKursWirdAbgelehnt(): void {
		$doppel = $this->bestand();
		$eingabe = $this->eingabe()->mitTagen('2026-09-12', '2026-09-13')
			->mitAnmeldeschluss('2026-09-05');

		$this->expectException(KursGibtEsSchon::class);
		$this->anlegen($doppel)->legeAn($eingabe, 'anna', $this->jetzt());
	}

	/**
	 * Sie steht VOR dem ersten Klon, und das ist ihr ganzer Sinn: Eine
	 * Meldung danach waere wertlos, weil dann schon ein halber Kurs in
	 * Nextcloud staende.
	 */
	public function testBeiVorhandenemKursWirdNichtsGeschrieben(): void {
		$doppel = $this->bestand();
		$eingabe = $this->eingabe()->mitTagen('2026-09-12', '2026-09-13')
			->mitAnmeldeschluss('2026-09-05');

		try {
			$this->anlegen($doppel)->legeAn($eingabe, 'anna', $this->jetzt());
		} catch (KursGibtEsSchon) {
			// erwartet
		}

		$this->assertNotContains('formularKlonen:24', $doppel->aufrufe);
		$this->assertSame([], $this->protokoll->zeilen);
	}

	public function testEineBelegteSperreWeistAb(): void {
		$doppel = $this->bestand();

		$this->expectException(GeradeBeschaeftigt::class);
		$this->anlegen($doppel, sperreFrei: false)
			->legeAn($this->eingabe(), 'anna', $this->jetzt());
	}

	/**
	 * Die Eingabepruefung bleibt AUSSERHALB der Sperre. Sie fasst Nextcloud
	 * nicht an und soll auch dann sofort antworten, wenn gerade jemand
	 * anlegt.
	 */
	public function testFalscheAngabenMeldenSichAuchBeiBelegterSperre(): void {
		$doppel = $this->bestand();

		$this->expectException(InvalidArgumentException::class);
		$this->anlegen($doppel, sperreFrei: false)
			->legeAn($this->eingabe()->mitPlaetzen(0), 'anna', $this->jetzt());
	}

	/**
	 * Jeder Abbruch laesst nichts stehen.
	 *
	 * Der Test laesst JEDEN Schritt der Schreibkette scheitern, nicht nur
	 * einen. Sonst bliebe ein Loeschbefehl im Fehlerfall ungeprueft.
	 */
	public function testJederAbbruchLaesstNichtsStehen(): void {
		$schritte = [
			'formularKlonen:24', 'formularAendern:100', 'formularHolen:100',
			'frageAendern:100/40', 'linkFreigabeAnlegen:100',
			'formularKlonen:23', 'formularHolen:101', 'formularAendern:101',
			'frageAendern:101/30', 'linkFreigabeAnlegen:101',
			'gruppenFreigabeAnlegen:100', 'gruppenFreigabeAnlegen:101',
		];

		foreach ($schritte as $schritt) {
			$doppel = $this->bestand();
			$doppel->laessScheitern($schritt);

			try {
				$this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());
				$this->fail('Bei ' . $schritt . ' haette es scheitern muessen.');
			} catch (AnlegenFehlgeschlagen) {
				// erwartet
			}

			$uebrig = array_map(
				static fn (Formular $formular): int => $formular->id, $doppel->bestand());
			sort($uebrig);
			$this->assertSame([19, 23, 24], $uebrig,
				'Nach dem Abbruch bei ' . $schritt . ' steht etwas Fremdes in Nextcloud.');
		}
	}

	/**
	 * Nur wenn das Aufraeumen selbst misslingt, bleibt etwas stehen - und
	 * nur dann heisst der Vorgang "abgebrochen". Der Unterschied ist keine
	 * Kosmetik: Nur der eine verlangt, dass jemand etwas tut.
	 */
	public function testEinGelungenesAufraeumenHeisstZurueckgerollt(): void {
		$doppel = $this->bestand();
		// Schritt 11, also NACH beiden Klonen: Nur dann kennt die Kette
		// beide ids und kann sie wirklich wegraeumen. Scheitert schon ein
		// Klon-Aufruf, bleibt offen, ob ein Formular entstanden ist - dafuer
		// steht testEinAbgerissenerKlonWirdNichtAlsNichtsGemeldet.
		$doppel->laessScheitern('linkFreigabeAnlegen:101');

		try {
			$this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());
		} catch (AnlegenFehlgeschlagen) {
			// erwartet
		}

		$this->assertSame('kurs.anlegen_zurueckgerollt', $this->protokoll->letzter()->vorgang);
	}

	/**
	 * Ein Klon, dessen Antwort verloren ging, darf nicht als "nichts
	 * entstanden" gemeldet werden.
	 *
	 * Die id kennt der Aufrufer erst aus der Antwort. Bleibt die aus, weiss
	 * die Kette nicht, was sie wegraeumen soll - raeumeAuf([]) gibt [], und
	 * die Meldung hiess "Es ist nichts entstanden. Bitte noch einmal
	 * versuchen." In Nextcloud lag aber ein Klon "VORLAGE … - Kopie", den
	 * Kursliste als Vorlage aussortiert: Niemand wurde je aufgefordert, ihn
	 * zu loeschen, weil die Meldung das Gegenteil behauptete.
	 */
	public function testEinAbgerissenerKlonWirdNichtAlsNichtsGemeldet(): void {
		$doppel = $this->bestand();
		$doppel->laessNachDerWirkungScheitern('formularKlonen:24');

		try {
			$this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (AnlegenFehlgeschlagen $fehler) {
			$this->assertStringNotContainsString(
				'Es ist nichts entstanden', $fehler->getMessage());
			$this->assertStringContainsString('Nextcloud', $fehler->getMessage());
		}

		// Der Klon steht wirklich da: drei Formulare aus dem Bestand plus
		// die Kopie.
		$this->assertCount(4, $doppel->bestand());
	}

	/**
	 * Das Praefix "Schritt N von M" steht GENAU EINMAL in der Meldung.
	 *
	 * Baut das Zurueckrollen die neue Ausnahme aus getMessage(), steht es
	 * zweimal - die alte Meldung trug es schon. Ein Suchen faende das nie:
	 * Ein doppeltes Praefix enthaelt das erwartete ja. Gezaehlt werden
	 * muss, nicht gesucht.
	 */
	public function testDieSchrittnummerStehtNurEinmalInDerMeldung(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('formularKlonen:23');

		try {
			$this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (AnlegenFehlgeschlagen $fehler) {
			$this->assertSame(1, substr_count($fehler->getMessage(), 'Schritt 6 von 13'));
		}
	}

	public function testEinMisslungenesAufraeumenHeisstAbgebrochen(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('formularKlonen:23');
		$doppel->laessLoeschenScheitern(100);

		try {
			$this->anlegen($doppel)->legeAn($this->eingabe(), 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (AnlegenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('Das Aufräumen misslang', $fehler->getMessage());
			$this->assertStringContainsString('/apps/forms/', $fehler->getMessage());
			$this->assertSame(1, substr_count($fehler->getMessage(), 'Schritt 6 von 13'));
		}

		$this->assertSame('kurs.anlegen_abgebrochen', $this->protokoll->letzter()->vorgang);
		$this->assertSame(100, $this->protokoll->letzter()->wartelisteId);
	}
}
