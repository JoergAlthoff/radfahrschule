<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\KursGibtEsSchon;
use OCA\Radfahrschule\Fachlogik\KursNichtGefunden;
use OCA\Radfahrschule\Fachlogik\Kursverschieben;
use OCA\Radfahrschule\Fachlogik\VerschiebenFehlgeschlagen;
use OCA\Radfahrschule\Fachlogik\Verschiebung;
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

final class KursverschiebenTest extends TestCase {
	use MitTitelmuster;

	private const BEDINGUNGSTEXT = "- **Termin:** 12./13.09.2026\n";

	private ProtokollDoppel $protokoll;

	protected function setUp(): void {
		$this->protokoll = new ProtokollDoppel();
	}

	private function bestand(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 1_000_000,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 2_000_000,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
		]);
	}

	private function verschiebung(
		string $von = '2026-11-14',
		string $bis = '2026-11-15',
		string $schluss = '2026-11-07',
	): Verschiebung {
		$zone = new DateTimeZone('UTC');
		return new Verschiebung(
			von: new DateTimeImmutable($von, $zone),
			bis: new DateTimeImmutable($bis, $zone),
			anmeldeschluss: new DateTimeImmutable($schluss, $zone),
		);
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	private function verschieben(FormulareDoppel $doppel, bool $sperreFrei = true): Kursverschieben {
		$lockingProvider = $this->createStub(ILockingProvider::class);
		if (!$sperreFrei) {
			$lockingProvider->method('acquireLock')
				->willThrowException(new LockedException('belegt'));
		}

		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');

		return new Kursverschieben(
			$doppel, $this->protokoll, new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)), $zugangsdaten, $this->titelmuster(), $this->ablauf());
	}

	public function testDerKursBekommtDenNeuenTermin(): void {
		$doppel = $this->bestand();

		$plan = $this->verschieben($doppel)->verschiebe(
			'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());

		$this->assertSame('Anfängerkurs 14./15.11.2026', $plan->neueKennung);
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 14./15.11.2026',
			$doppel->formularHolen(19)->titel);
	}

	/**
	 * Es entstehen KEINE neuen Formulare. Loeschen und neu anlegen schiede
	 * aus: Verschoben wird gerade dann, wenn schon Anmeldungen da sind.
	 */
	public function testEsEntstehenKeineNeuenFormulare(): void {
		$doppel = $this->bestand();

		$this->verschieben($doppel)->verschiebe(
			'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());

		$ids = array_map(static fn (Formular $formular): int => $formular->id, $doppel->bestand());
		sort($ids);
		$this->assertSame([18, 19], $ids);
		$this->assertNotContains('formularKlonen:19', $doppel->aufrufe);
	}

	public function testEineUnbekannteKennungAendertNichts(): void {
		$doppel = $this->bestand();

		$this->expectException(KursNichtGefunden::class);
		$this->verschieben($doppel)->verschiebe(
			'Gibt es nicht', $this->verschiebung(), 'anna', $this->jetzt());
	}

	/**
	 * Auf einen Termin, der schon vergeben ist, wird nicht verschoben - sonst
	 * traegt der Bestand zwei Kurse mit identischer Kennung, und die
	 * Uebersicht kann sie nicht mehr trennen.
	 */
	public function testEinVergebenerTerminWirdAbgelehnt(): void {
		$doppel = $this->bestand();
		// Auf dem Zieltermin steht schon ein Kurs.
		$klon = $doppel->formularKlonen(19);
		$doppel->formularAendern($klon->id, [
			'title' => 'Radfahrschule Musterstadt — Anfängerkurs 14./15.11.2026']);

		$this->expectException(KursGibtEsSchon::class);
		$this->verschieben($doppel)->verschiebe(
			'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());
	}

	public function testEineBelegteSperreWeistAb(): void {
		$doppel = $this->bestand();

		$this->expectException(GeradeBeschaeftigt::class);
		$this->verschieben($doppel, sperreFrei: false)->verschiebe(
			'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());
	}

	public function testDasProtokollNenntBeideKennungen(): void {
		$doppel = $this->bestand();

		$this->verschieben($doppel)->verschiebe(
			'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());

		$zeile = $this->protokoll->letzter();
		$this->assertSame('kurs.verschoben', $zeile->vorgang);
		$this->assertStringContainsString('12./13.09.2026', $zeile->kennung);
		$this->assertStringContainsString('14./15.11.2026', $zeile->kennung);
		$this->assertSame(6, $zeile->anmeldungen);
	}

	/**
	 * Scheitert die Kette, steht der alte Termin wieder - und der Vorgang
	 * heisst "zurueckgerollt", nicht "abgebrochen". Der Unterschied ist keine
	 * Kosmetik: Nur der zweite verlangt, dass jemand etwas tut.
	 */
	public function testEinGescheiterterLaufStelltDenAltenTerminWiederHer(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('frageAendern:19/30');

		try {
			$this->verschieben($doppel)->verschiebe(
				'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('alten Termin', $fehler->getMessage());
		}

		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(19)->titel);
		$this->assertSame('Warteliste — Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(18)->titel);
		$this->assertSame('kurs.verschieben_zurueckgerollt', $this->protokoll->letzter()->vorgang);
	}

	/**
	 * Das Schreiben scheitert am letzten Schritt, und das Heilen scheitert
	 * ebenfalls - an einem Aufruf, der beim Schreiben noch gelang.
	 *
	 * Deshalb der Zaehler im Doppel: Zurueckgeschrieben wird mit denselben
	 * Aufrufen wie geschrieben. Ohne ihn liesse sich der Fall gar nicht
	 * nachstellen.
	 */
	public function testEinMisslungenesZurueckschreibenHeisstAbgebrochen(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('frageAendern:19/30');
		$doppel->laessBeimZweitenMalScheitern('formularAendern:19');

		try {
			$this->verschieben($doppel)->verschiebe(
				'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('Zurückschreiben misslang', $fehler->getMessage());
			$this->assertStringContainsString('/apps/forms/', $fehler->getMessage());
		}

		$this->assertSame('kurs.verschieben_abgebrochen', $this->protokoll->letzter()->vorgang);
	}

	/**
	 * Die Schrittnummer steht GENAU EINMAL in der Meldung.
	 *
	 * Wird die neue Ausnahme aus getMessage() gebaut, traegt sie das
	 * Praefix ein zweites Mal. Ein Suchen faende das nie - eine doppelte
	 * Nummer enthaelt die erwartete ja. Gezaehlt werden muss, nicht
	 * gesucht.
	 */
	public function testDieSchrittnummerStehtNurEinmalInDerMeldung(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('frageAendern:19/30');

		try {
			$this->verschieben($doppel)->verschiebe(
				'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertSame(1, substr_count($fehler->getMessage(), 'Schritt 4 von 4'));
		}
	}

	/**
	 * Scheitert es beim EINLESEN, wurde noch nichts geschrieben - dann gibt
	 * es auch nichts zurueckzuschreiben und keine Protokollzeile.
	 */
	public function testEinFehlerBeimEinlesenSchreibtNichtsUndProtokolliertNichts(): void {
		$ohneTerminzeile = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', 'ohne Terminzeile')]),
		]);

		try {
			$this->verschieben($ohneTerminzeile)->verschiebe(
				'Anfängerkurs 12./13.09.2026', $this->verschiebung(), 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (VerschiebenFehlgeschlagen $fehler) {
			$this->assertFalse($fehler->schonGeschrieben);
		}

		$this->assertSame([], $this->protokoll->zeilen);
	}
}
