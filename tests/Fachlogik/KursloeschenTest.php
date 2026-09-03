<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kursloeschen;
use OCA\Radfahrschule\Fachlogik\KursNichtGefunden;
use OCA\Radfahrschule\Fachlogik\LoeschenFehlgeschlagen;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Sperre\Schreibsperre;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Protokoll\ProtokollDoppel;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class KursloeschenTest extends TestCase {
	use MitTitelmuster;

	private ProtokollDoppel $protokoll;

	protected function setUp(): void {
		$this->protokoll = new ProtokollDoppel();
	}

	private function bestand(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 0),
			// Eine Vorlage, die nie getroffen werden darf.
			new Formular(id: 23, hash: 'editor0000000023',
				titel: 'VORLAGE Anmeldung - Anfängerkurs',
				beschreibung: '', abgaben: 0, ablauf: 0),
		]);
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	private function loeschen(
		FormulareDoppel $doppel,
		bool $sperreFrei = true,
	): Kursloeschen {
		$lockingProvider = $this->createStub(ILockingProvider::class);
		if (!$sperreFrei) {
			$lockingProvider->method('acquireLock')
				->willThrowException(new LockedException('belegt'));
		}

		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');

		return new Kursloeschen($doppel, $this->protokoll,
			new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)), $zugangsdaten, $this->titelmuster());
	}

	/**
	 * Dieselbe Sperre wie beim Anlegen und Verschieben.
	 *
	 * Zwei gleichzeitige Loeschungen waeren harmlos - die zweite faende
	 * nichts mehr. Loeschen GEGEN Verschieben ist es nicht: Der
	 * Verschiebende aendert dann Formulare, die es nicht mehr gibt, und
	 * auch das Zurueckrollen scheitert.
	 */
	public function testEineBelegteSperreWeistAb(): void {
		$doppel = $this->bestand();

		$this->expectException(GeradeBeschaeftigt::class);
		$this->loeschen($doppel, sperreFrei: false)->loesche(
			'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());
	}

	/** Nichts wurde angefasst - die Sperre greift VOR dem ersten Aufruf. */
	public function testBeiBelegterSperreWirdNichtsGeloescht(): void {
		$doppel = $this->bestand();

		try {
			$this->loeschen($doppel, sperreFrei: false)->loesche(
				'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());
		} catch (GeradeBeschaeftigt) {
			// erwartet
		}

		$this->assertSame([], $doppel->aufrufe);
	}

	public function testBeideFormulareGehenWeg(): void {
		$doppel = $this->bestand();

		$this->loeschen($doppel)->loesche(
			'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());

		$uebrig = array_map(
			static fn (Formular $formular): int => $formular->id, $doppel->bestand());
		$this->assertSame([23], $uebrig);
	}

	/**
	 * Die Anmeldung geht zuerst. Bricht es danach ab, bleibt eine Warteliste
	 * ohne Anmeldung - unschoen, aber harmlos. Andersherum stuende eine
	 * Anmeldung da, deren Wartelisten-Link ins Leere fuehrt.
	 */
	public function testDieAnmeldungGehtZuerst(): void {
		$doppel = $this->bestand();

		$this->loeschen($doppel)->loesche(
			'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());

		$this->assertSame(
			['formularLoeschen:19', 'formularLoeschen:18'],
			array_values(array_filter(
				$doppel->aufrufe,
				static fn (string $aufruf): bool => str_starts_with($aufruf, 'formularLoeschen'),
			)),
		);
	}

	/**
	 * Gesucht wird ueber die KENNUNG. Das erledigt zwei Pruefungen, ohne sie
	 * zu schreiben: Gemischte ids aus zwei Kursen sind unmoeglich, und eine
	 * Vorlage ist nie treffbar - sie traegt keine Kennung.
	 */
	public function testEineUnbekannteKennungLoeschtNichts(): void {
		$doppel = $this->bestand();

		try {
			$this->loeschen($doppel)->loesche('Gibt es nicht', 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (KursNichtGefunden) {
			// erwartet
		}

		$this->assertCount(3, $doppel->bestand());
		$this->assertSame([], $this->protokoll->zeilen);
	}

	public function testDasProtokollHaeltFestWerGeloeschtHat(): void {
		$doppel = $this->bestand();

		$this->loeschen($doppel)->loesche(
			'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());

		$zeile = $this->protokoll->letzter();
		$this->assertNotNull($zeile);
		$this->assertSame('kurs.geloescht', $zeile->vorgang);
		$this->assertSame('anna', $zeile->benutzer);
		$this->assertSame('Anfängerkurs 12./13.09.2026', $zeile->kennung);
		$this->assertSame('2026-09-13', $zeile->kurstag);
		$this->assertSame(6, $zeile->anmeldungen);
	}

	/**
	 * Die Kennung steht unter demselben Feldnamen wie beim Anlegen und in
	 * derselben Schreibweise - ohne Praefix. Aus dem Feld wird eine Spalte;
	 * zwei Schreibweisen darin liessen sich nicht mehr paaren.
	 */
	public function testAnlegenUndLoeschenSchreibenDieselbeKennung(): void {
		$doppel = $this->bestand();

		$this->loeschen($doppel)->loesche(
			'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());

		$this->assertSame('Anfängerkurs 12./13.09.2026', $this->protokoll->letzter()->kennung);
	}

	/**
	 * Es wird NICHT zurueckgerollt: Ein geloeschtes Formular laesst sich
	 * nicht wiederherstellen. Der Aufrufer erfaehrt stattdessen, was noch
	 * steht - mit Link, nicht mit Nummer.
	 */
	public function testEinAbbruchNenntWasNochSteht(): void {
		$doppel = $this->bestand();
		$doppel->laessLoeschenScheitern(18);

		try {
			$this->loeschen($doppel)->loesche(
				'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (LoeschenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('Warteliste', $fehler->getMessage());
			$this->assertStringContainsString('Was schon weg ist, bleibt weg',
				$fehler->getMessage());
			$this->assertStringContainsString('/apps/forms/editor0000000018/edit',
				$fehler->getMessage());
		}

		// Die Anmeldung ist wirklich weg - nichts wurde wiederhergestellt.
		$uebrig = array_map(
			static fn (Formular $formular): int => $formular->id, $doppel->bestand());
		sort($uebrig);
		$this->assertSame([18, 23], $uebrig);

		$this->assertSame('kurs.loeschen_abgebrochen', $this->protokoll->letzter()->vorgang);
		$this->assertSame(18, $this->protokoll->letzter()->wartelisteId);
	}

	/** Drei Formulare in einem Kurs: Anmeldung, Warteliste, ein doppeltes. */
	private function bestandMitDoppeltem(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 0),
			new Formular(id: 20, hash: 'editor0000000020',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 3, ablauf: 0),
		]);
	}

	/** @return list<int> */
	private function uebrigeIds(FormulareDoppel $doppel): array {
		$ids = array_map(
			static fn (Formular $formular): int => $formular->id, $doppel->bestand());
		sort($ids);
		return array_values($ids);
	}

	/**
	 * Ein Fehlschlag darf die uebrigen Formulare nicht aufhalten.
	 *
	 * Vorher brach die Schleife beim ersten Fehler ab. Bei einem doppelt
	 * angelegten Kurs blieben damit Formulare stehen, die niemand versucht
	 * hatte - samt Anmeldedaten, waehrend die 90-Tage-Frist unbeobachtet
	 * verstreicht. Die Schwesterschleife Zurueckrollen::raeumeAuf macht an
	 * derselben Stelle bewusst weiter.
	 */
	public function testEinFehlschlagHaeltDieUebrigenNichtAuf(): void {
		$doppel = $this->bestandMitDoppeltem();
		$doppel->laessLoeschenScheitern(18);

		try {
			$this->loeschen($doppel)->loesche(
				'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (LoeschenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('Warteliste', $fehler->getMessage());
		}

		// Das doppelte steht NACH der Warteliste in der Loeschliste. Es muss
		// trotzdem weg sein.
		$this->assertSame([18], $this->uebrigeIds($doppel));
	}

	/**
	 * Bleiben mehrere stehen, muessen auch alle genannt werden - sonst
	 * loescht jemand das eine genannte und haelt den Kurs fuer erledigt.
	 */
	public function testJedesSteckengebliebeneFormularWirdGenannt(): void {
		$doppel = $this->bestandMitDoppeltem();
		$doppel->laessLoeschenScheitern(18);
		$doppel->laessLoeschenScheitern(20);

		try {
			$this->loeschen($doppel)->loesche(
				'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());
			$this->fail('Es haette scheitern muessen.');
		} catch (LoeschenFehlgeschlagen $fehler) {
			$this->assertStringContainsString('/apps/forms/editor0000000018/edit',
				$fehler->getMessage());
			$this->assertStringContainsString('/apps/forms/editor0000000020/edit',
				$fehler->getMessage());
			$this->assertStringContainsString('Doppelt angelegt', $fehler->getMessage());
		}

		$this->assertSame([18, 20], $this->uebrigeIds($doppel));
	}

	/**
	 * Ein doppelt angelegtes Formular geht mit weg. Bliebe es stehen,
	 * meldete das Protokoll Erfolg, waehrend Anmeldedaten in Nextcloud
	 * zurueckbleiben.
	 */
	public function testEinDoppeltesFormularGehtMitWeg(): void {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0),
			new Formular(id: 20, hash: 'editor0000000020',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 1, ablauf: 0),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 0),
		]);

		$this->loeschen($doppel)->loesche(
			'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());

		$this->assertSame([], $doppel->bestand());
	}

	/** Der geloeschte Kurs kommt zurueck, damit die Seite ihn benennen kann. */
	public function testDerGeloeschteKursKommtZurueck(): void {
		$doppel = $this->bestand();

		$kurs = $this->loeschen($doppel)->loesche(
			'Anfängerkurs 12./13.09.2026', 'anna', $this->jetzt());

		$this->assertSame('Anfängerkurs 12./13.09.2026', $kurs->kennung);
		$this->assertNull($kurs->fehlendeHaelfte());
	}
}
