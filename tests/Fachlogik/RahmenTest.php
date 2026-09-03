<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use PHPUnit\Framework\TestCase;

class RahmenTest extends TestCase {
	// Container und Mac liegen auseinander: Nextcloud laeuft auf PHP 8.3.33,
	// der Mac hat 8.5. Die Fachlogik-Tests laufen hier, die App dort.
	// Deshalb sichert dieser Test die Mac-Version zu, nicht die des
	// Containers - und die Fachlogik benutzt nichts, was juenger als 8.3 ist.
	public function testDerTestrahmenLaeuft(): void {
		$this->assertSame('8.5', substr(PHP_VERSION, 0, 3),
			'Erwartet wird PHP 8.5, gefunden: ' . PHP_VERSION);
	}
}
