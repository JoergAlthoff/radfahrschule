<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use OCA\Radfahrschule\Formulare\Formular;

/**
 * Ein Formular des Kurses mit der Beschriftung, unter der es dem Menschen
 * begegnet.
 *
 * Die Beschriftung ist nicht der Titel: Sie sagt die Rolle im Kurs
 * ("Anmeldung", "Warteliste"), damit eine Meldung sie nennen kann, ohne den
 * ganzen Titel auszuschreiben.
 */
final readonly class Kurszeile {
	public function __construct(
		public string $beschriftung,
		public Formular $formular,
	) {
	}
}
