<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use RuntimeException;
use Throwable;

/**
 * Ein Schritt des Verschiebens scheiterte.
 *
 * Die Gesamtzahl wird GERECHNET, nicht festgeschrieben: Je Formular fallen
 * zwei Schreibaufrufe an, und ein Kurs kann aus einem einzelnen Formular
 * bestehen oder aus mehr als zweien. Eine feste Zahl ergaebe "Schritt 5 von
 * 4" - in einem Text, den jemand liest.
 *
 * "schonGeschrieben" trennt zwei Ausgaenge, die der Controller verschieden
 * behandelt: Vor dem ersten Schreibaufruf ist in Nextcloud nichts geschehen;
 * danach nennt die Meldung womoeglich Formulare zum Aufraeumen. Wer beides
 * unter dieselbe Ueberschrift stellt, schickt jemanden nach Ueberresten
 * suchen, die es nicht gibt.
 */
final class VerschiebenFehlgeschlagen extends RuntimeException {
	public function __construct(
		public readonly string $ursache,
		public readonly bool $schonGeschrieben = false,
		public readonly ?int $schritt = null,
		public readonly ?int $schritteInsgesamt = null,
		public readonly string $zusatz = '',
		?Throwable $previous = null,
	) {
		$meldung = $schritt === null
			? $ursache
			: sprintf('Schritt %d von %d fehlgeschlagen: %s',
				$schritt, $schritteInsgesamt, $ursache);

		if ($zusatz !== '') {
			$meldung .= "\n\n" . $zusatz;
		}

		parent::__construct($meldung, 0, $previous);
	}
}
