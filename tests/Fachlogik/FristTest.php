<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\Frist;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

class FristTest extends TestCase {
	use MitTitelmuster;

	// Die Testfaelle tragen eine Uhrzeit. Eine Frist ist ein Tag, kein
	// Zeitpunkt: "spaetestens am 12.12." heisst bis zu dessen Ende. Ein
	// Test, der jetzt immer auf Mitternacht setzt, ist gruen - das ist die
	// eine Uhrzeit, an der beide Lesarten dasselbe ergeben.
	public function testVorAblaufNenntDenTagDerHandlung(): void {
		$letzterTag = new DateTimeImmutable('2026-09-13', new DateTimeZone('UTC'));
		$jetzt = new DateTimeImmutable('2026-09-20 08:30:00', new DateTimeZone('UTC'));

		$this->assertSame(
			'Muss spätestens am 12.12.2026 gelöscht werden.',
			$this->frist()->text($letzterTag, $jetzt),
		);
	}

	public function testAmFristtagSelbstNochNichtUeberfaellig(): void {
		$letzterTag = new DateTimeImmutable('2026-09-13', new DateTimeZone('UTC'));
		$jetzt = new DateTimeImmutable('2026-12-12 00:01:00', new DateTimeZone('UTC'));

		$this->assertStringContainsString(
			'spätestens', $this->frist()->text($letzterTag, $jetzt));
	}

	public function testEinenTagSpaeterUeberfaellig(): void {
		$letzterTag = new DateTimeImmutable('2026-09-13', new DateTimeZone('UTC'));
		$jetzt = new DateTimeImmutable('2026-12-13 00:01:00', new DateTimeZone('UTC'));

		$this->assertSame(
			'Hätte am 12.12.2026 gelöscht werden müssen.',
			$this->frist()->text($letzterTag, $jetzt),
		);
	}

	public function testOhneKurstagKeineFrist(): void {
		$jetzt = new DateTimeImmutable('2026-08-25 12:00:00', new DateTimeZone('UTC'));

		$this->assertSame('', $this->frist()->text(null, $jetzt));
	}

	/**
	 * Die Zahl steht in den Einstellungen, nicht im Code. Ein anderer
	 * Betreiber hat eine andere Frist im Formular stehen - und die bindet
	 * ihn, nicht die neunzig Tage dieses Vereins.
	 */
	public function testEineAndereFristVerschiebtDenTag(): void {
		$letzterTag = new DateTimeImmutable('2026-09-13', new DateTimeZone('UTC'));
		$jetzt = new DateTimeImmutable('2026-09-20 08:30:00', new DateTimeZone('UTC'));

		$this->assertSame(
			'Muss spätestens am 13.10.2026 gelöscht werden.',
			$this->frist(['aufbewahrung_tage' => '30'])->text($letzterTag, $jetzt),
		);
	}

	/** @param array<string, string> $werte */
	private function frist(array $werte = []): Frist {
		return new Frist($this->betreiberangaben($werte));
	}
}
