<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use RuntimeException;
use Throwable;

/**
 * Ein Schritt der Schreibkette scheiterte.
 *
 * Die Schrittnummer reist mit, weil sie sagt, wie weit der Lauf kam - und
 * genau das entscheidet, was danach noch in Nextcloud steht.
 *
 * Was mit dem halb Angelegten geschieht, steht hier NICHT: Das entscheidet
 * das Zurueckrollen, und erst dessen Ausgang sagt, ob jemand etwas tun muss.
 * Sein Ergebnis kommt als zusatz dazu.
 */
final class AnlegenFehlgeschlagen extends RuntimeException {
	/**
	 * Die reine Ursache OHNE das Praefix "Schritt N von M".
	 *
	 * Sie steht getrennt, damit sich eine Meldung ergaenzen laesst, ohne das
	 * Praefix ein zweites Mal davorzusetzen. Genau das passierte beim
	 * Zurueckrollen: Im Browser stand "Schritt 8 von 15 fehlgeschlagen:
	 * Schritt 8 von 15 fehlgeschlagen: …". Im Test fiel es nicht auf - ein
	 * doppeltes Praefix enthaelt das erwartete ja.
	 */
	public function __construct(
		public readonly int $schritt,
		public readonly int $schritteInsgesamt,
		public readonly string $ursache,
		?Throwable $previous = null,
		string $zusatz = '',
	) {
		$meldung = sprintf('Schritt %d von %d fehlgeschlagen: %s',
			$schritt, $schritteInsgesamt, $ursache);

		if ($zusatz !== '') {
			$meldung .= "\n\n" . $zusatz;
		}

		parent::__construct($meldung, 0, $previous);
	}
}
