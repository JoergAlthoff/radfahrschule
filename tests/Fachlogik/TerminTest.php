<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\Termin;
use PHPUnit\Framework\TestCase;

final class TerminTest extends TestCase {
	private function tag(string $datum): DateTimeImmutable {
		return new DateTimeImmutable($datum, new DateTimeZone('UTC'));
	}

	public function testZweiTageImSelbenMonat(): void {
		$this->assertSame(
			'12./13.09.2026',
			Termin::kurz($this->tag('2026-09-12'), $this->tag('2026-09-13')),
		);
	}

	public function testEinTag(): void {
		$this->assertSame(
			'12.09.2026',
			Termin::kurz($this->tag('2026-09-12'), $this->tag('2026-09-12')),
		);
	}

	public function testUeberDenMonatswechsel(): void {
		$this->assertSame(
			'30.09./01.10.2026',
			Termin::kurz($this->tag('2026-09-30'), $this->tag('2026-10-01')),
		);
	}

	/**
	 * Ohne den Jahresvergleich fielen September 2026 und September 2027
	 * zusammen, und aus zwei Tagen ein Jahr auseinander wuerde "12./13.09.".
	 */
	public function testUeberDenJahreswechsel(): void {
		$this->assertSame(
			'31.12./01.01.2027',
			Termin::kurz($this->tag('2026-12-31'), $this->tag('2027-01-01')),
		);
	}

	/**
	 * Rein in Ziffern. Ein Wochentags- oder Monatsname kaeme aus der
	 * Systemsprache, und die ist je Rechner anders gesetzt - im Container
	 * stuende dann ein englischer Monat im Formulartitel.
	 */
	public function testKeinBuchstabeImTermin(): void {
		$termin = Termin::kurz($this->tag('2026-09-12'), $this->tag('2026-09-13'));

		$this->assertMatchesRegularExpression('/^[0-9.\/]+$/', $termin);
	}
}
