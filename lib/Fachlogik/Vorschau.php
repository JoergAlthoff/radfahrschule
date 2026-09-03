<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Was der Dienst anlegen wuerde - gerechnet, aber noch nicht geschrieben.
 *
 * In Nextcloud laesst sich nichts rueckgaengig machen: Ein Tippfehler im
 * Jahr faellt hier auf, nicht danach.
 */
final readonly class Vorschau {
	private function __construct(
		public Eingabe $eingabe,
		public string $titelAnmeldung,
		public string $titelWarteliste,
		/**
		 * Der gemeinsame Rest beider Titel.
		 *
		 * NICHT der volle Titel: Unter diesem Wert wird protokolliert, und
		 * das Loeschen schreibt dort die Kennung ohne Praefix. Aus dem Feld
		 * wird eine Spalte - zwei Schreibweisen darin liessen sich nicht
		 * mehr paaren. Jede Zeile saehe dabei fuer sich richtig aus; der
		 * Widerspruch zeigt sich erst nebeneinander.
		 */
		public string $kennung,
		public string $termin,
		public int $ablaufAnmeldung,
		public int $ablaufWarteliste,
		public int $plaetze,
	) {
	}

	/**
	 * Rechnet alle Texte aus, ohne Nextcloud anzufassen.
	 *
	 * jetzt reicht nur bis zur Pruefung durch - die Texte selbst haengen
	 * allein an der Eingabe.
	 *
	 * @throws InvalidArgumentException wenn die Angaben nicht passen
	 */
	public static function rechne(
		Eingabe $eingabe,
		DateTimeImmutable $jetzt,
		Titelmuster $titelmuster,
		Ablauf $ablauf,
	): self {
		$meldung = $eingabe->pruefe($jetzt);
		if ($meldung !== null) {
			throw new InvalidArgumentException($meldung);
		}

		$titelAnmeldung = $titelmuster->fuerAnmeldung($eingabe->von, $eingabe->bis);

		return new self(
			eingabe: $eingabe,
			titelAnmeldung: $titelAnmeldung,
			titelWarteliste: $titelmuster->fuerWarteliste($eingabe->von, $eingabe->bis),
			kennung: $titelmuster->zerlege($titelAnmeldung)->kennung,
			termin: Termin::kurz($eingabe->von, $eingabe->bis),
			ablaufAnmeldung: $ablauf->fuerAnmeldung($eingabe->anmeldeschluss),
			ablaufWarteliste: $ablauf->fuerWarteliste($eingabe->bis),
			plaetze: $eingabe->plaetze,
		);
	}

	/**
	 * Die Kalendertage in der Form, die <input type="date"> sendet und
	 * annimmt. Getrennt von der Anzeige, weil ein Mensch 10.10.2026 liest.
	 */
	public function vonIso(): string {
		return $this->eingabe->von->format('Y-m-d');
	}

	public function bisIso(): string {
		return $this->eingabe->bis->format('Y-m-d');
	}

	public function anmeldeschlussIso(): string {
		return $this->eingabe->anmeldeschluss->format('Y-m-d');
	}
}
