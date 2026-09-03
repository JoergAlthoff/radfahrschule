<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use RuntimeException;

/**
 * Unter dieser Kennung steht schon ein Kurs.
 *
 * Ein eigener Fehler, und zwar aus einem Grund, der in der Oberflaeche
 * sichtbar ist: Bricht ein Lauf mittendrin ab, stehen halbe Formulare in
 * Nextcloud und jemand muss aufraeumen. Hier wurde NICHTS geschrieben.
 * Dieselbe Ueberschrift fuer beides schickte denselben Menschen los, um
 * etwas zu suchen, das es nicht gibt.
 */
final class KursGibtEsSchon extends RuntimeException {
	public function __construct(string $kennung) {
		parent::__construct(sprintf(
			'Diesen Kurs gibt es schon: „%s". Wenn er neu entstehen soll, '
			. 'zuerst den alten löschen.', $kennung));
	}
}
