<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Benachrichtigung;

use OCA\Radfahrschule\Formulare\Empfaenger;

/**
 * Betreff und Text einer Mail, mit Platzhaltern.
 *
 * Der Verein schreibt den Text einmal. Fuer jeden Empfaenger setzt fuer()
 * dessen Angaben ein: {anrede}, {vorname}, {nachname}, dazu {kursart} und
 * {termin} des Kurses, beim Verschieben {neuer_termin}. Den Ton waehlt der
 * Verein - "Guten Tag {anrede} {nachname}" oder "Hallo {vorname}".
 *
 * Fachlogik\Textersetzung passt hier nicht. Sie ersetzt feste Stellen im
 * Vorlagentext von Forms und verlangt genau einen Treffer.
 */
final readonly class Nachricht {
	public function __construct(
		public string $betreff,
		public string $text,
		private string $kursart,
		private string $termin,
		private string $neuerTermin = '',
	) {
	}

	/**
	 * Die Nachricht fuer einen Empfaenger.
	 *
	 * strtr ersetzt in einem Durchgang. Steht in einem Namen selbst
	 * "{termin}", bleibt das stehen, statt noch einmal ersetzt zu werden.
	 *
	 * Fehlt die Anrede, entstuende "Guten Tag  Muster". Mehrere Leerzeichen
	 * werden deshalb zu einem.
	 *
	 * Der fertige Betreff wird als Ganzes noch einmal einzeilig gemacht: Ein
	 * Zeilenumbruch kann auch aus der Betreffvorlage selbst kommen, nicht
	 * nur aus einem eingesetzten Wert.
	 */
	public function fuer(Empfaenger $empfaenger): self {
		$werte = [
			'{anrede}' => self::einzeilig($empfaenger->anrede),
			'{vorname}' => self::einzeilig($empfaenger->vorname),
			'{nachname}' => self::einzeilig($empfaenger->nachname),
			'{kursart}' => $this->kursart,
			'{termin}' => $this->termin,
			'{neuer_termin}' => $this->neuerTermin,
		];

		return new self(
			betreff: self::ohneDoppelteLeerzeichen(self::einzeilig(strtr($this->betreff, $werte))),
			text: self::ohneDoppelteLeerzeichen(strtr($this->text, $werte)),
			kursart: $this->kursart,
			termin: $this->termin,
			neuerTermin: $this->neuerTermin,
		);
	}

	/** Ein leerer Text heisst: Diese Liste bekommt nichts. */
	public function istLeer(): bool {
		return trim($this->text) === '';
	}

	/**
	 * Ein Zeilenumbruch - aus einer Abgabe oder aus der Betreffvorlage
	 * selbst - gehoert nicht in den Betreff. Dort beendete er die Kopfzeile
	 * der Mail.
	 */
	private static function einzeilig(string $wert): string {
		return str_replace(["\r", "\n"], ' ', $wert);
	}

	private static function ohneDoppelteLeerzeichen(string $text): string {
		return (string)preg_replace('/ {2,}/', ' ', $text);
	}
}
