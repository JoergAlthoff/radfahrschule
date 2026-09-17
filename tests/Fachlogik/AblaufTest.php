<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

final class AblaufTest extends TestCase {
	use MitTitelmuster;

	private function tag(string $datum): DateTimeImmutable {
		return new DateTimeImmutable($datum, new DateTimeZone('UTC'));
	}

	/** Der Abend des Anmeldeschlusses - so zaehlt der Tag ganz. */
	public function testDieAnmeldungSchliesstAmAbend(): void {
		$sekunden = $this->ablauf()->fuerAnmeldung($this->tag('2026-09-05'));

		$this->assertSame('2026-09-05 23:59', $this->alsBerlinerZeit($sekunden));
	}

	/**
	 * Mittag des letzten Kurstages, NICHT der Anmeldeschluss: Bis dahin kann
	 * jemand abspringen, und bis dahin soll sich jemand vormerken koennen.
	 */
	public function testDieWartelisteSchliesstAmKurstagMittags(): void {
		$sekunden = $this->ablauf()->fuerWarteliste($this->tag('2026-09-13'));

		$this->assertSame('2026-09-13 12:00', $this->alsBerlinerZeit($sekunden));
	}

	/**
	 * Zwischen Sommer- und Winterzeit liegt eine Stunde. Waeren die
	 * Zeitpunkte in UTC gerechnet, schloesse ein Kurs im Sommer zwei Stunden
	 * zu frueh - hier ist der Unterschied in Unix-Sekunden sichtbar.
	 */
	public function testSommerUndWinterzeitLiegenEineStundeAuseinander(): void {
		$imSommer = $this->ablauf()->fuerWarteliste($this->tag('2026-07-01'));
		$imWinter = $this->ablauf()->fuerWarteliste($this->tag('2026-12-01'));

		// Mittag im Sommer ist 10:00 UTC, im Winter 11:00 UTC.
		$this->assertSame(10, (int)gmdate('H', $imSommer));
		$this->assertSame(11, (int)gmdate('H', $imWinter));
	}

	/**
	 * Die Zone kommt aus den Einstellungen. Ein Betreiber anderswo schliesst
	 * seine Warteliste zu einer anderen Unix-Sekunde: Mittag in Berlin ist im
	 * Sommer 10:00 UTC, Mittag in Reykjavik das ganze Jahr 12:00 UTC.
	 */
	public function testEineAndereZeitzoneVerschiebtDenZeitpunkt(): void {
		$inBerlin = $this->ablauf()->fuerWarteliste($this->tag('2026-07-01'));
		$inReykjavik = $this->ablauf(['zeitzone' => 'Atlantic/Reykjavik'])
			->fuerWarteliste($this->tag('2026-07-01'));

		$this->assertSame(2 * 3600, $inReykjavik - $inBerlin);
	}

	private function alsBerlinerZeit(int $sekunden): string {
		return (new DateTimeImmutable('@' . $sekunden))
			->setTimezone(new DateTimeZone('Europe/Berlin'))
			->format('Y-m-d H:i');
	}
}
