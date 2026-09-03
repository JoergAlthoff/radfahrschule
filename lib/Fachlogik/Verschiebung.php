<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;

/**
 * Die Angaben, mit denen ein Kurs einen neuen Termin bekommt.
 *
 * Keine Vorlagen und keine Platzzahl: Beim Verschieben bleiben sie, wie sie
 * sind. Die Formulare stehen bereits, es wird nichts geklont.
 */
final readonly class Verschiebung {
	public function __construct(
		public DateTimeImmutable $von,
		public DateTimeImmutable $bis,
		public DateTimeImmutable $anmeldeschluss,
	) {
	}

	/**
	 * Haelt die Angaben gegen dieselben Regeln wie das Anlegen.
	 *
	 * Sie stehen in Eingabe und werden von dort geholt, nicht abgeschrieben:
	 * Liefe eine Seite der anderen davon, verboete sie, was die andere
	 * erlaubt, und das faellt erst dem Benutzer auf.
	 */
	public function pruefe(DateTimeImmutable $jetzt): ?string {
		// Die Vorlagen-ids und die Platzzahl sind hier ohne Bedeutung. Sie
		// bekommen gueltige Platzhalterwerte, damit die drei Terminregeln
		// zur Anwendung kommen und sonst nichts.
		$alsEingabe = new Eingabe(
			vorlageAnmeldung: 1,
			vorlageWarteliste: 2,
			von: $this->von,
			bis: $this->bis,
			anmeldeschluss: $this->anmeldeschluss,
			plaetze: 1,
		);

		return $alsEingabe->pruefe($jetzt);
	}
}
