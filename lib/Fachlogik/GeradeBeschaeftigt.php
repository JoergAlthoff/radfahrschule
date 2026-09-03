<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use RuntimeException;

/**
 * Es laeuft schon ein Anlegen.
 *
 * Ein eigener Fehler, weil die Folge eine andere ist: Hier wurde NICHTS
 * geschrieben. Unter der Ueberschrift "Das Anlegen brach ab" wuerde jemand
 * in Nextcloud nach Ueberresten suchen, die es nicht gibt.
 */
final class GeradeBeschaeftigt extends RuntimeException {
	public function __construct() {
		parent::__construct('Gerade legt jemand anderes einen Kurs an.');
	}
}
