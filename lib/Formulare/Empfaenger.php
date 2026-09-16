<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

/**
 * Wer eine Nachricht bekommt: vier Angaben aus einer Abgabe.
 *
 * Forms gibt eine Abgabe im Ganzen heraus, also auch Alter, Koerpergroesse
 * und Kommentar. Diese Klasse nimmt vier Felder und laesst den Rest liegen.
 * Was hier nicht steht, verlaesst die Formulare nicht.
 *
 * Gesucht wird ueber den technischen Namen der Frage. Der sichtbare
 * Fragetext gehoert dem Betreiber, und ein anderer Verein nennt das Feld
 * anders. Die Fragen-ID scheidet auch aus: Jeder Klon einer Vorlage bekommt
 * neue.
 */
final readonly class Empfaenger {
	public function __construct(
		public string $anrede,
		public string $vorname,
		public string $nachname,
		public string $mailadresse,
	) {
	}

	/**
	 * @param array<string, mixed> $abgabe ein Eintrag aus "submissions"
	 */
	public static function ausAbgabe(array $abgabe): self {
		$antworten = [];
		foreach ((array)($abgabe['answers'] ?? []) as $eintrag) {
			$antwort = (array)$eintrag;
			$name = (string)($antwort['questionName'] ?? '');
			// Die erste Antwort je Frage. Mehrere gibt es nur bei
			// Ankreuzfragen, und von denen liest diese Klasse keine.
			$antworten[$name] ??= trim((string)($antwort['text'] ?? ''));
		}

		return new self(
			anrede: $antworten['anrede'] ?? '',
			vorname: $antworten['vorname'] ?? '',
			nachname: $antworten['nachname'] ?? '',
			mailadresse: $antworten['email'] ?? '',
		);
	}

	/** Vor- und Nachname, fuer den Mailkopf und die Ergebnisseite. */
	public function name(): string {
		return trim($this->vorname . ' ' . $this->nachname);
	}
}
