<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Radfahrschule\Fachlogik\Eingabe;
use OCA\Radfahrschule\Fachlogik\Vorschau;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

final class VorschauTest extends TestCase {
	use MitTitelmuster;

	private function gueltig(): Eingabe {
		$zone = new DateTimeZone('UTC');
		return new Eingabe(
			vorlageAnmeldung: 23,
			vorlageWarteliste: 24,
			von: new DateTimeImmutable('2026-10-10', $zone),
			bis: new DateTimeImmutable('2026-10-11', $zone),
			anmeldeschluss: new DateTimeImmutable('2026-10-03', $zone),
			plaetze: 6,
		);
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	public function testSieRechnetBeideTitel(): void {
		$vorschau = Vorschau::rechne($this->gueltig(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame(
			'Radfahrschule Musterstadt — Anfängerkurs 10./11.10.2026',
			$vorschau->titelAnmeldung,
		);
		$this->assertSame(
			'Warteliste — Anfängerkurs 10./11.10.2026',
			$vorschau->titelWarteliste,
		);
	}

	public function testDieKennungIstBeidenGemeinsam(): void {
		$vorschau = Vorschau::rechne($this->gueltig(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame('Anfängerkurs 10./11.10.2026', $vorschau->kennung);
	}

	public function testDieAblaufzeitenStehen(): void {
		$vorschau = Vorschau::rechne($this->gueltig(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertGreaterThan(0, $vorschau->ablaufAnmeldung);
		// Die Warteliste laeuft LAENGER als die Anmeldung: Mittag des
		// Kurstages gegen Abend des Anmeldeschlusses.
		$this->assertGreaterThan($vorschau->ablaufAnmeldung, $vorschau->ablaufWarteliste);
	}

	/**
	 * Ein <input type="date"> verlangt ISO. Teilte es sich ein Feld mit der
	 * Anzeige, stuende auf der Seite 2026-10-10 statt 10.10.2026.
	 */
	public function testDieVerstecktenFelderStehenInIso(): void {
		$vorschau = Vorschau::rechne($this->gueltig(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame('2026-10-10', $vorschau->vonIso());
		$this->assertSame('2026-10-11', $vorschau->bisIso());
		$this->assertSame('2026-10-03', $vorschau->anmeldeschlussIso());
	}

	public function testSieRechnetNichtsBeiFalschenAngaben(): void {
		$this->expectException(InvalidArgumentException::class);

		Vorschau::rechne($this->gueltig()->mitPlaetzen(0), $this->jetzt(), $this->titelmuster(), $this->ablauf());
	}

	/**
	 * Die Meldung der Pruefung reist mit. Ohne sie stuende auf der Seite
	 * "Die Angaben passen nicht" und sonst nichts.
	 */
	public function testDieMeldungDerPruefungReistMit(): void {
		try {
			Vorschau::rechne($this->gueltig()->mitPlaetzen(0), $this->jetzt(), $this->titelmuster(), $this->ablauf());
			$this->fail('Es haette scheitern muessen.');
		} catch (InvalidArgumentException $fehler) {
			$this->assertStringContainsString('Platzzahl', $fehler->getMessage());
		}
	}
}
