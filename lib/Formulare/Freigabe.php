<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

/**
 * Eine Freigabe eines Formulars, soweit dieser Dienst sie braucht.
 *
 * Die Rechte stehen nicht darin: Gelesen wird nur, wer es sehen darf, nie
 * was er darf.
 */
final readonly class Freigabe {
	/** Die shareType-Nummern von Nextcloud. */
	public const TYP_GRUPPE = 1;
	public const TYP_LINK = 3;

	public function __construct(
		public int $id,
		public int $typ,
		public string $ziel,
	) {
	}

	/**
	 * @param array<string, mixed> $antwort
	 */
	public static function ausAntwort(array $antwort): self {
		return new self(
			id: (int)($antwort['id'] ?? 0),
			typ: (int)($antwort['shareType'] ?? -1),
			// shareWith traegt bei einer Link-Freigabe den Hash, bei einer
			// Gruppen- oder Kontofreigabe den Namen. NICHT mit der id
			// verwechseln - das ist die Nummer der Freigabe selbst.
			ziel: (string)($antwort['shareWith'] ?? ''),
		);
	}
}
