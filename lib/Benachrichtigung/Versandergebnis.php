<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Benachrichtigung;

/**
 * Was nach dem Versand feststeht: wie viele Mails hinausgingen und an wen
 * nicht.
 *
 * Die Namen derer, bei denen es scheiterte, stehen nur auf der Seite danach.
 * Der Bediener sieht sie ohnehin in Forms; gespeichert werden sie nicht.
 */
final readonly class Versandergebnis {
	/** @param list<string> $gescheitert Vor- und Nachname je Fehlschlag */
	public function __construct(
		public int $verschickt,
		public array $gescheitert,
	) {
	}

	public function satz(): string {
		return match ($this->verschickt) {
			0 => 'Es ist keine Nachricht hinausgegangen.',
			1 => '1 Nachricht ist hinausgegangen.',
			default => $this->verschickt . ' Nachrichten sind hinausgegangen.',
		};
	}
}
