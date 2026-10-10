<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

/**
 * Was nach dem Nachruecken feststeht.
 *
 * Ein Einwand heisst: Es wurde nichts versucht, die Auswahl muss geaendert
 * werden. Eine Stoerung heisst: Der Lauf hat begonnen und musste anhalten.
 *
 * Die Namen stehen nur auf der Seite danach. Wer die App bedient, sieht
 * sie ohnehin in Forms. Gespeichert werden sie nicht.
 */
final readonly class Nachrueckergebnis {
	/**
	 * @param list<string> $nachgerueckt wer jetzt in der Anmeldung steht
	 * @param string $stoerung der Satz zu der Person, bei der es anhielt
	 * @param list<string> $uebrig wer deshalb nicht mehr drankam
	 */
	public function __construct(
		public array $nachgerueckt = [],
		public string $stoerung = '',
		public array $uebrig = [],
		public ?string $einwand = null,
	) {
	}

	public static function abgewiesen(string $einwand): self {
		return new self(einwand: $einwand);
	}

	public function satz(): string {
		$anzahl = count($this->nachgerueckt);
		return match ($anzahl) {
			0 => 'Niemand ist nachgerückt.',
			1 => '1 Person ist nachgerückt.',
			default => $anzahl . ' Personen sind nachgerückt.',
		};
	}
}
