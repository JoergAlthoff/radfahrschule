<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;

/**
 * Die Ablaufzeitpunkte der beiden Formulare, in Unix-Sekunden.
 *
 * Gerechnet wird in der Zone des Vereins und NICHT in UTC: Forms erwartet
 * Unix-Sekunden, und zwischen Sommer- und Winterzeit liegt eine Stunde. Wer
 * hier UTC nimmt, schliesst im Sommer zwei Stunden zu frueh.
 */
class Ablauf {
	public function __construct(private Zeitzone $zeitzone) {
	}

	/** Der Abend des Anmeldeschlusses. So zaehlt der Tag ganz. */
	public function fuerAnmeldung(DateTimeImmutable $anmeldeschluss): int {
		return $this->zeitpunkt($anmeldeschluss, 23, 59);
	}

	/**
	 * Mittag des letzten Kurstages - nicht der Anmeldeschluss. Bis dahin
	 * kann jemand abspringen, und bis dahin soll sich jemand vormerken
	 * koennen.
	 */
	public function fuerWarteliste(DateTimeImmutable $letzterKurstag): int {
		return $this->zeitpunkt($letzterKurstag, 12, 0);
	}

	private function zeitpunkt(
		DateTimeImmutable $kalendertag,
		int $stunde,
		int $minute,
	): int {
		$ortszeit = new DateTimeImmutable(
			sprintf('%s %02d:%02d:00', $kalendertag->format('Y-m-d'), $stunde, $minute),
			$this->zeitzone->desVereins(),
		);

		return $ortszeit->getTimestamp();
	}
}
