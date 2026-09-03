<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use RuntimeException;

/**
 * Zu dieser Kennung gibt es keinen Kurs.
 *
 * Der haeufigste Anlass ist harmlos: Jemand hatte die Seite offen, waehrend
 * ein anderer den Kurs geloescht hat. Deshalb sagt die Meldung, was zu tun
 * ist, statt einen Fehler zu behaupten.
 */
final class KursNichtGefunden extends RuntimeException {
	public function __construct(string $kennung) {
		parent::__construct(sprintf(
			'Zu „%s" gibt es keinen Kurs. Bitte von der Übersicht neu beginnen.',
			$kennung));
	}
}
