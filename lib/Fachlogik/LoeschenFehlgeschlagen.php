<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use RuntimeException;
use Throwable;

/**
 * Ein Formular liess sich nicht entfernen.
 *
 * Es gibt hier NICHTS zurueckzurollen: Was schon geloescht ist, bleibt
 * geloescht - Nextcloud hat keinen Papierkorb, die Antworten sind mit weg.
 * Die Meldung sagt deshalb, was noch steht und wo man es findet - und zwar
 * JEDES steckengebliebene Formular. Nennt sie nur eines, loescht der
 * Bediener dieses und haelt den Kurs fuer erledigt, waehrend die uebrigen
 * samt Anmeldedaten weiterlaufen.
 *
 * Sie wird vom Aufrufer gebaut: Nur der kennt die Basis-Adresse, aus der
 * die anklickbaren Editor-Links entstehen.
 */
final class LoeschenFehlgeschlagen extends RuntimeException {
	public function __construct(string $meldung, ?Throwable $previous = null) {
		parent::__construct($meldung, 0, $previous);
	}
}
