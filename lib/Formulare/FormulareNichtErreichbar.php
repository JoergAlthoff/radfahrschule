<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

use RuntimeException;
use Throwable;

/**
 * Ein Aufruf an Forms ist gescheitert.
 *
 * "status" ist der HTTP-Status, wenn Forms geantwortet hat. Ohne Antwort
 * bleibt er null, und dann weiss der Aufrufer nicht, ob der Aufruf
 * angekommen ist.
 */
class FormulareNichtErreichbar extends RuntimeException {
	public function __construct(
		string $message = '',
		public readonly ?int $status = null,
		?Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	/**
	 * Forms hat den Aufruf angenommen und abgelehnt. Geschrieben wurde
	 * dann nichts.
	 */
	public function istAblehnung(): bool {
		return $this->status !== null && $this->status >= 400 && $this->status < 500;
	}
}
