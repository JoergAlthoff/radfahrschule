<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

/**
 * Eine ganze Abgabe aus einem Formular.
 *
 * Gebraucht wird sie nur fuer das Nachruecken: Die App reicht die Antworten
 * an ein anderes Formular derselben Instanz weiter. Abgelegt wird nichts.
 *
 * Die Antworten haengen am technischen Namen der Frage. Die Fragen-ID
 * taugt nicht, jedes Formular hat eigene. Antworten auf Fragen ohne
 * technischen Namen sind nicht dabei, ihre Zahl steht in
 * antwortenOhneFragenname.
 */
final readonly class Abgabe {
	/** @param array<string, list<string>> $antworten Name der Frage zu allen Antworttexten */
	public function __construct(
		public int $id,
		public int $zeitpunkt,
		public array $antworten,
		public int $antwortenOhneFragenname = 0,
	) {
	}

	/**
	 * @param array<string, mixed> $abgabe ein Eintrag aus "submissions"
	 */
	public static function ausAntwort(array $abgabe): self {
		$antworten = [];
		$ohneFragenname = 0;
		foreach ((array)($abgabe['answers'] ?? []) as $eintrag) {
			$antwort = (array)$eintrag;
			$name = (string)($antwort['questionName'] ?? '');
			if ($name === '') {
				$ohneFragenname++;
				continue;
			}
			$antworten[$name][] = (string)($antwort['text'] ?? '');
		}

		return new self(
			id: (int)($abgabe['id'] ?? 0),
			zeitpunkt: (int)($abgabe['timestamp'] ?? 0),
			antworten: $antworten,
			antwortenOhneFragenname: $ohneFragenname,
		);
	}

	/** Vor- und Nachname, fuer die Liste und die Ergebnisseite. */
	public function name(): string {
		return trim($this->erste('vorname') . ' ' . $this->erste('nachname'));
	}

	private function erste(string $frage): string {
		return trim($this->antworten[$frage][0] ?? '');
	}
}
