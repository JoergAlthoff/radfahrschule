<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Benachrichtigung;

use OCA\Radfahrschule\Benachrichtigung\Versandergebnis;
use PHPUnit\Framework\TestCase;

final class VersandergebnisTest extends TestCase {
	public function testDerSatzZaehltRichtig(): void {
		$this->assertSame('Es ist keine Nachricht hinausgegangen.', (new Versandergebnis(0, []))->satz());
		$this->assertSame('1 Nachricht ist hinausgegangen.', (new Versandergebnis(1, []))->satz());
		$this->assertSame('9 Nachrichten sind hinausgegangen.', (new Versandergebnis(9, []))->satz());
	}
}
