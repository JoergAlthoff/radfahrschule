<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;

/**
 * Die Datumsangabe fuer Titel und Bedingungstext: "12./13.09.2026".
 *
 * Rein in Ziffern, damit kein Wochentags- oder Monatsname aus der
 * Systemsprache kommt - die ist je Rechner anders gesetzt.
 */
final class Termin {
	public static function kurz(DateTimeImmutable $von, DateTimeImmutable $bis): string {
		if (self::amSelbenTag($von, $bis)) {
			return $von->format('d.m.Y');
		}
		if (self::imSelbenMonat($von, $bis)) {
			return $von->format('d.') . '/' . $bis->format('d.m.Y');
		}
		return $von->format('d.m.') . '/' . $bis->format('d.m.Y');
	}

	/**
	 * Vergleicht den Kalendertag, nicht den Zeitpunkt. Ein Vergleich auf
	 * Gleichheit waere falsch, sobald eine Uhrzeit mitreist: Zwei Uhrzeiten
	 * desselben Tages gaelten dann als Zeitraum.
	 */
	private static function amSelbenTag(DateTimeImmutable $von, DateTimeImmutable $bis): bool {
		return $von->format('Y-m-d') === $bis->format('Y-m-d');
	}

	/** Monat UND Jahr. Ohne das Jahr fielen zwei Septembers zusammen. */
	private static function imSelbenMonat(DateTimeImmutable $von, DateTimeImmutable $bis): bool {
		return $von->format('Y-m') === $bis->format('Y-m');
	}
}
