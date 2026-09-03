<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

/**
 * Die Werte eines Formulars vor dem Verschieben.
 *
 * Sie werden gemerkt, damit ein gescheiterter Lauf sie zurueckschreiben
 * kann. Gelesen werden sie ohnehin - die Terminzeile steht nur im einzelnen
 * Formular, nicht in der Liste.
 *
 * Ein Merker "schon geschrieben" gehoert NICHT dazu. Er koennte nicht
 * unterscheiden, ob ein Aufruf abgelehnt wurde oder nur seine Antwort
 * verloren ging - fuer den Anrufer sieht beides gleich aus. Zurueckschreiben
 * liest deshalb den Ist-Stand und vergleicht.
 */
final readonly class AlterStand {
	public function __construct(
		public string $beschriftung,
		public int $id,
		public string $hash,
		public string $titel,
		public int $ablauf,
		public int $frageId,
		public string $fragetext,
	) {
	}
}
