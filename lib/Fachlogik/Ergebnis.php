<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

/** Was nach einem gelungenen Lauf in Nextcloud steht. */
final readonly class Ergebnis {
	public function __construct(
		public int $anmeldungId,
		public int $wartelisteId,
		/** Der SHARE-Hash, 24 Zeichen - nicht der Editor-Hash. */
		public string $anmeldungHash,
		public string $wartelisteHash,
	) {
	}
}
