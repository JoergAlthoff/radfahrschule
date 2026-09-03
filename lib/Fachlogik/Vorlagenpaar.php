<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use OCA\Radfahrschule\Formulare\Formular;

/**
 * Die beiden Auswahlfelder zusammen.
 *
 * Nextcloud wird einmal gefragt und die Liste zweimal gefiltert. Zwei
 * Aufrufe koennten einen Stand liefern, in dem sich die Formularliste
 * zwischendurch geaendert hat.
 */
final readonly class Vorlagenpaar {
	private function __construct(
		public Vorlagenauswahl $anmeldung,
		public Vorlagenauswahl $warteliste,
	) {
	}

	/** @param list<Formular> $formulare */
	public static function ausFormularen(array $formulare): self {
		return new self(
			Vorlagenauswahl::ausFormularen($formulare, Formularart::Anmeldung),
			Vorlagenauswahl::ausFormularen($formulare, Formularart::Warteliste),
		);
	}

	/**
	 * Die Titelanfaenge, zu denen keine Vorlage steht.
	 *
	 * Beide Arten werden gebraucht: Ein Kurs besteht aus Anmeldung UND
	 * Warteliste. Genannt wird nur, was fehlt - wer die vorhandene Art in
	 * der Meldung liest, sucht vergeblich nach ihr.
	 *
	 * @return list<string>
	 */
	public function fehlendeArten(): array {
		$fehlende = [];
		if ($this->anmeldung->istLeer()) {
			$fehlende[] = Vorlagenauswahl::PRAEFIX_ANMELDUNG;
		}
		if ($this->warteliste->istLeer()) {
			$fehlende[] = Vorlagenauswahl::PRAEFIX_WARTELISTE;
		}
		return $fehlende;
	}
}
