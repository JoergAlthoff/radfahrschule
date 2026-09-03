<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

/**
 * Ein Klon, den DIESER Lauf angelegt hat - und nur ein solcher darf
 * zurueckgerollt werden.
 *
 * Der Hash steht dabei, weil eine ID einem Menschen nichts sagt: In
 * Nextcloud ist nirgends eine Formularnummer zu sehen. Aus dem Hash wird
 * eine Adresse, die man anklicken kann.
 *
 * Es ist der EDITOR-Hash, 16 Zeichen. Genau der ist hier richtig: Gemeint
 * ist die Seite zum Bearbeiten und Loeschen, nicht die zum Anmelden.
 */
final readonly class ErzeugtesFormular {
	public function __construct(
		public string $beschriftung,
		public int $id,
		public string $hash,
	) {
	}
}
