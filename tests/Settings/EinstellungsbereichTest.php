<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Settings;

use OCA\Radfahrschule\Settings\Einstellungsbereich;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class EinstellungsbereichTest extends TestCase {
	/**
	 * Die Kennung verbindet den Bereich mit der Einstellungsseite: Verwaltung
	 * nennt in getSection() genau diese Zeichenkette. Weichen beide ab, taucht
	 * der Eintrag in der linken Spalte auf und bleibt leer.
	 */
	public function testDieKennungIstDieDerApp(): void {
		$this->assertSame('radfahrschule', $this->bereich()->getID());
	}

	public function testDerNameStehtInDerSpalte(): void {
		$this->assertSame('Radfahrschule', $this->bereich()->getName());
	}

	/**
	 * Nextcloud verlangt einen Wert zwischen 0 und 99 und sortiert aufsteigend.
	 */
	public function testDieReihenfolgeLiegtImErlaubtenBereich(): void {
		$reihenfolge = $this->bereich()->getPriority();

		$this->assertGreaterThanOrEqual(0, $reihenfolge);
		$this->assertLessThanOrEqual(99, $reihenfolge);
	}

	/**
	 * Die linke Spalte hat einen hellen Hintergrund, deshalb gehoert dorthin
	 * die dunkle Variante - nicht das weisse Icon der Kopfzeile.
	 */
	public function testDasIconIstDieDunkleVariante(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('imagePath')
			->with('radfahrschule', 'radfahrschule-dark.svg')
			->willReturn('/apps/radfahrschule/img/radfahrschule-dark.svg');

		$bereich = new Einstellungsbereich($urlGenerator);

		$this->assertSame('/apps/radfahrschule/img/radfahrschule-dark.svg', $bereich->getIcon());
	}

	private function bereich(): Einstellungsbereich {
		return new Einstellungsbereich($this->createStub(IURLGenerator::class));
	}
}
