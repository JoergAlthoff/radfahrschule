<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

/**
 * Was Forms ueber ein Formular sagt - soweit dieser Dienst es braucht.
 *
 * Die Abgaben sind hier eine Zahl, kein Personenbezug. Die Antworten selbst
 * liegen unter einem eigenen Endpunkt; gelesen werden sie nur fuer eine
 * Nachricht, ueber Formulare::empfaenger.
 */
final readonly class Formular {
	/**
	 * @param list<Frage> $fragen
	 * @param list<Freigabe> $freigaben
	 */
	public function __construct(
		public int $id,
		public string $hash,
		public string $titel,
		public string $beschreibung,
		public int $abgaben,
		public int $ablauf,
		public array $fragen = [],
		public array $freigaben = [],
		/** Wie viele Abgaben das Formular annimmt. Null: keine Grenze. */
		public ?int $platzzahl = null,
	) {
	}

	/**
	 * @param array<string, mixed> $antwort
	 */
	public static function ausAntwort(array $antwort): self {
		$fragen = [];
		foreach ((array)($antwort['questions'] ?? []) as $eintrag) {
			$fragen[] = Frage::ausAntwort((array)$eintrag);
		}

		$freigaben = [];
		foreach ((array)($antwort['shares'] ?? []) as $eintrag) {
			$freigaben[] = Freigabe::ausAntwort((array)$eintrag);
		}

		$platzzahl = (int)($antwort['maxSubmissions'] ?? 0);

		return new self(
			id: (int)($antwort['id'] ?? 0),
			hash: (string)($antwort['hash'] ?? ''),
			titel: (string)($antwort['title'] ?? ''),
			// description, questions und shares liefert nur GET /forms/{id},
			// nicht die Liste.
			beschreibung: (string)($antwort['description'] ?? ''),
			abgaben: (int)($antwort['submissionCount'] ?? 0),
			ablauf: (int)($antwort['expires'] ?? 0),
			fragen: $fragen,
			freigaben: $freigaben,
			platzzahl: $platzzahl > 0 ? $platzzahl : null,
		);
	}

	/** Sucht eine Frage ueber ihren technischen Schluessel. */
	public function frageMitNamen(string $name): ?Frage {
		foreach ($this->fragen as $frage) {
			if ($frage->name === $name) {
				return $frage;
			}
		}
		return null;
	}

	/** Wie viele Abgaben noch passen. Null, wenn es keine Platzzahl gibt. */
	public function freiePlaetze(): ?int {
		if ($this->platzzahl === null) {
			return null;
		}
		return max(0, $this->platzzahl - $this->abgaben);
	}

	/**
	 * Der Hash der Link-Freigabe, oder null.
	 *
	 * NICHT das Feld hash nehmen: Das ist die Editor-Adresse mit 16
	 * Zeichen, der Share-Hash hat 24. Verwechselt fuehrt der Link ins Leere.
	 */
	public function oeffentlicherHash(): ?string {
		foreach ($this->freigaben as $freigabe) {
			if ($freigabe->typ === Freigabe::TYP_LINK) {
				return $freigabe->ziel;
			}
		}
		return null;
	}
}
