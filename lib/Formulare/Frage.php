<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

/**
 * Eine Frage eines Formulars.
 *
 * "name" ist der technische Schluessel, ueber den eine Frage wiedererkannt
 * wird - nicht ueber Text oder Position. Der Bedingungstext steht in
 * "beschreibung", nicht in "text".
 *
 * "auswahl" ist bei Text- und Zahlenfragen leer. Bei Auswahlfragen nennt
 * es zu jeder Nummer den Text, den Forms beim Lesen einer Abgabe liefert.
 */
final readonly class Frage {
	/** @param array<int, string> $auswahl Nummer der Auswahlmoeglichkeit zu ihrem Text */
	public function __construct(
		public int $id,
		public string $name,
		public string $text,
		public string $beschreibung,
		public array $auswahl = [],
	) {
	}

	/**
	 * @param array<string, mixed> $antwort
	 */
	public static function ausAntwort(array $antwort): self {
		$auswahl = [];
		foreach ((array)($antwort['options'] ?? []) as $eintrag) {
			$option = (array)$eintrag;
			$auswahl[(int)($option['id'] ?? 0)] = (string)($option['text'] ?? '');
		}

		return new self(
			id: (int)($antwort['id'] ?? 0),
			name: (string)($antwort['name'] ?? ''),
			text: (string)($antwort['text'] ?? ''),
			beschreibung: (string)($antwort['description'] ?? ''),
			auswahl: $auswahl,
		);
	}
}
