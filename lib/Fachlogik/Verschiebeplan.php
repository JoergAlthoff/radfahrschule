<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Was der Dienst aendern wuerde - gerechnet, aber noch nicht geschrieben.
 *
 * Er traegt die ALTEN Werte mit, weil die Kontrollseite beide nebeneinander
 * zeigt. Wer nur den neuen Stand sieht, kann nicht pruefen, ob er den
 * richtigen Kurs erwischt hat.
 */
final readonly class Verschiebeplan {
	private function __construct(
		public Verschiebung $verschiebung,
		public string $alteKennung,
		public string $neueKennung,
		public string $alterTitelAnmeldung,
		public string $neuerTitelAnmeldung,
		public string $alterTitelWarteliste,
		public string $neuerTitelWarteliste,
		public string $termin,
		public int $ablaufAnmeldung,
		public int $ablaufWarteliste,
	) {
	}

	/** @throws InvalidArgumentException wenn die Angaben nicht passen */
	public static function rechne(
		Kurs $kurs,
		Verschiebung $verschiebung,
		DateTimeImmutable $jetzt,
		Titelmuster $titelmuster,
		Ablauf $ablauf,
	): self {
		$meldung = $verschiebung->pruefe($jetzt);
		if ($meldung !== null) {
			throw new InvalidArgumentException($meldung);
		}

		$neueKennung = self::neueKennungAus($kurs->kennung, $verschiebung);
		$ablaufAnmeldung = $ablauf->fuerAnmeldung($verschiebung->anmeldeschluss);

		// Die Kennung haengt allein an von/bis. Wer nur die Anmeldefrist
		// verlaengert, aendert sie nicht - und bekam trotzdem "steht bereits
		// auf diesem Termin" zu lesen, obwohl das Verschieben den neuen Wert
		// sehr wohl schreiben wuerde. Die Frist war damit nach dem Anlegen
		// unveraenderlich.
		//
		// Ohne Anmeldung gibt es nichts, was ein Anmeldeschluss aendern
		// koennte: Die Warteliste laeuft nach ihrer eigenen Regel aus.
		$terminUnveraendert = $neueKennung === $kurs->kennung;
		$schlussUnveraendert = $kurs->anmeldung === null
			|| $kurs->anmeldung->ablauf === $ablaufAnmeldung;
		if ($terminUnveraendert && $schlussUnveraendert) {
			throw new InvalidArgumentException(
				'An diesem Kurs ändert sich dadurch nichts: Termin und '
				. 'Anmeldeschluss stehen schon so.');
		}

		// Die alten Titel kommen aus den Formularen, nicht aus der Kennung.
		// Ist eine Haelfte in Nextcloud umbenannt worden und deshalb aus dem
		// Paar gefallen, gibt es sie hier nicht - und die Kontrollseite darf
		// sie dann auch nicht zeigen. Verschiebekette::leseAllesEin fasst
		// sie ohnehin nicht an.
		$alterTitelAnmeldung = $kurs->anmeldung === null ? '' : $kurs->anmeldung->titel;
		$alterTitelWarteliste = $kurs->warteliste === null ? '' : $kurs->warteliste->titel;

		return new self(
			verschiebung: $verschiebung,
			alteKennung: $kurs->kennung,
			neueKennung: $neueKennung,
			alterTitelAnmeldung: $alterTitelAnmeldung,
			neuerTitelAnmeldung: $titelmuster->praefixAnmeldung() . $neueKennung,
			alterTitelWarteliste: $alterTitelWarteliste,
			neuerTitelWarteliste: $titelmuster->praefixWarteliste() . $neueKennung,
			termin: Termin::kurz($verschiebung->von, $verschiebung->bis),
			ablaufAnmeldung: $ablaufAnmeldung,
			ablaufWarteliste: $ablauf->fuerWarteliste($verschiebung->bis),
		);
	}

	/**
	 * Setzt den neuen Termin hinter die bisherige Kursart.
	 *
	 * Die Kursart wird UEBERNOMMEN und nicht neu gesetzt: Sie steht in der
	 * Kennung, damit eine andere Reihe sich richtig paart. Ein fest
	 * eingetragener Wert machte aus jedem verschobenen Kurs still einen
	 * Anfaengerkurs.
	 *
	 * Der Termin ist das letzte Feld der Kennung; alles davor ist die
	 * Kursart. Steht kein Leerzeichen darin, gibt es keine Art, und die
	 * Kennung ist der Termin allein.
	 */
	private static function neueKennungAus(string $alteKennung, Verschiebung $verschiebung): string {
		$termin = Termin::kurz($verschiebung->von, $verschiebung->bis);

		$stelle = strrpos($alteKennung, ' ');
		if ($stelle === false) {
			return $termin;
		}
		return substr($alteKennung, 0, $stelle + 1) . $termin;
	}

	public function vonIso(): string {
		return $this->verschiebung->von->format('Y-m-d');
	}

	public function bisIso(): string {
		return $this->verschiebung->bis->format('Y-m-d');
	}

	public function anmeldeschlussIso(): string {
		return $this->verschiebung->anmeldeschluss->format('Y-m-d');
	}
}
