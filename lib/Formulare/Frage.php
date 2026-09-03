<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

/**
 * Eine Frage eines Formulars.
 *
 * "name" ist der technische Schluessel, ueber den eine Frage wiedererkannt
 * wird - nicht ueber Text oder Position. Der Bedingungstext steht in
 * "beschreibung", nicht in "text".
 */
final readonly class Frage {
	public function __construct(
		public int $id,
		public string $name,
		public string $text,
		public string $beschreibung,
	) {
	}

	/**
	 * @param array<string, mixed> $antwort
	 */
	public static function ausAntwort(array $antwort): self {
		return new self(
			id: (int)($antwort['id'] ?? 0),
			name: (string)($antwort['name'] ?? ''),
			text: (string)($antwort['text'] ?? ''),
			beschreibung: (string)($antwort['description'] ?? ''),
		);
	}
}
