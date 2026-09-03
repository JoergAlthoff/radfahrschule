<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;

/**
 * Die Aufbewahrungsfrist fuer Anmeldedaten.
 *
 * Die Zahl steht im Formular und bindet den Betreiber. Sie kommt deshalb
 * aus den Einstellungen: Ein anderer Verein schreibt eine andere Frist in
 * sein Formular, und die gilt dann fuer ihn.
 *
 * NICHT final: Tests ersetzen die Klasse durch ein Doppel.
 */
class Frist {
	public function __construct(private Betreiberangaben $betreiberangaben) {
	}

	/**
	 * Sagt die Handlung, nicht den Zustand: Wer die Uebersicht ueberfliegt,
	 * soll ohne Nachdenken sehen, was ansteht.
	 */
	public function text(?DateTimeImmutable $letzterTag, DateTimeImmutable $jetzt): string {
		if ($letzterTag === null) {
			return '';
		}

		$tage = $this->betreiberangaben->aufbewahrungTage();
		$fristtag = $letzterTag->modify('+' . $tage . ' days');
		$heute = new DateTimeImmutable($jetzt->format('Y-m-d'), Zeitzone::zumAblegen());

		// Bis zum ENDE des Fristtages. Ein Vergleich auf Zeitpunkte meldete
		// ab 00:01 des Fristtages schon die Ueberschreitung.
		if ($heute <= $fristtag) {
			return sprintf('Muss spätestens am %s gelöscht werden.', $fristtag->format('d.m.Y'));
		}

		return sprintf('Hätte am %s gelöscht werden müssen.', $fristtag->format('d.m.Y'));
	}
}
