<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Protokoll;

/**
 * Die Naht zum Vorgangsprotokoll.
 *
 * Dasselbe Muster wie bei Formulare: Die Fachlogik kennt nur dieses
 * Interface, und der Test schreibt in nichts.
 */
interface Protokoll {
	public function schreibe(Vorgang $vorgang): void;
}
