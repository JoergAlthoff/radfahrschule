<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

/**
 * Ein zerlegter Formulartitel: seine Art und die Kennung.
 *
 * Die Kennung ist der gemeinsame Rest beider Titel eines Kurses und die
 * einzige Klammer zwischen Anmeldung und Warteliste - Nextcloud kennt keine
 * Kurse. Wer ein Formular umbenennt, zerlegt das Paar.
 *
 * Zerlegt und gebaut wird in Titelmuster. Dort liegen die Praefixe, denn
 * einer davon kommt aus den Betreiberangaben.
 */
final readonly class Titel {
	/**
	 * Der Anfang jedes Wartelistentitels.
	 *
	 * Eine Konstante, weil "Warteliste" die Rolle im Kurs benennt und nicht
	 * den Betreiber. Der Anmeldungs-Praefix steht dagegen in den
	 * Betreiberangaben.
	 */
	public const PRAEFIX_WARTELISTE = 'Warteliste — ';

	/**
	 * Der Anfang eines Vorlagentitels.
	 *
	 * Ebenfalls betreiberunabhaengig: Eine Vorlage ist eine Vorlage, egal
	 * wer die App betreibt.
	 */
	public const PRAEFIX_VORLAGE = 'VORLAGE ';

	private function __construct(
		public Formularart $art,
		public string $kennung,
	) {
	}

	public static function mit(Formularart $art, string $kennung): self {
		return new self($art, $kennung);
	}

	/** Eine Vorlage traegt keine Kennung - sie gehoert zu keinem Kurs. */
	public static function vorlage(): self {
		return new self(Formularart::Vorlage, '');
	}
}
