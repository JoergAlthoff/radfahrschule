<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Benachrichtigung;

use OCA\Radfahrschule\Benachrichtigung\Nachricht;
use OCA\Radfahrschule\Benachrichtigung\Versand;
use OCA\Radfahrschule\Formulare\Empfaenger;

/**
 * Bewahrt jede Nachricht auf, nicht nur die Zahl der Aufrufe.
 *
 * Ein Doppel, das nur zaehlt, haelt nichts: Der Test waere gruen, egal was
 * in der Mail steht. Geprueft wird damit, ob jede Adresse genau eine Mail
 * bekam und was darin steht.
 */
final class VersandDoppel implements Versand {
	/** @var list<array{empfaenger: Empfaenger, nachricht: Nachricht}> */
	public array $verschickt = [];

	/** @var list<string> */
	private array $scheitertBei = [];

	public function __construct(private bool $abgeschaltet = false) {
	}

	public function laessScheiternBei(string $mailadresse): void {
		$this->scheitertBei[] = $mailadresse;
	}

	public function istAbgeschaltet(): bool {
		return $this->abgeschaltet;
	}

	public function schicke(Empfaenger $empfaenger, Nachricht $nachricht): bool {
		if (in_array($empfaenger->mailadresse, $this->scheitertBei, true)) {
			return false;
		}
		$this->verschickt[] = ['empfaenger' => $empfaenger, 'nachricht' => $nachricht];
		return true;
	}

	/** @return list<string> jede Adresse so oft, wie sie eine Mail bekam */
	public function adressen(): array {
		return array_map(
			static fn (array $eintrag): string => $eintrag['empfaenger']->mailadresse,
			$this->verschickt);
	}

	public function nachrichtAn(string $mailadresse): ?Nachricht {
		foreach ($this->verschickt as $eintrag) {
			if ($eintrag['empfaenger']->mailadresse === $mailadresse) {
				return $eintrag['nachricht'];
			}
		}
		return null;
	}
}
