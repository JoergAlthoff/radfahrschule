<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Protokoll;

use OCA\Radfahrschule\Protokoll\Protokoll;
use OCA\Radfahrschule\Protokoll\Vorgang;

final class ProtokollDoppel implements Protokoll {
	/** @var list<Vorgang> */
	public array $zeilen = [];

	public function schreibe(Vorgang $vorgang): void {
		$this->zeilen[] = $vorgang;
	}

	public function letzter(): ?Vorgang {
		return $this->zeilen === [] ? null : $this->zeilen[count($this->zeilen) - 1];
	}
}
