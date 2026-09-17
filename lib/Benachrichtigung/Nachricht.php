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
	/**
	 * So viele Zeichen kommen hoechstens aus einem Feld der Abgabe in die
	 * Mail. Ein echter Name passt immer hinein, ein eingeschleuster Text
	 * kaum.
	 */
	public const HOECHSTLAENGE = 50;

	/**
	 * Alles ausser diesen Zeichen faellt aus einem Namen heraus: Buchstaben
	 * jeder Sprache, Akzente als eigenes Zeichen, Leerzeichen, Bindestrich und
	 * die beiden Apostrophe.
	 *
	 * Erlaubt wird, statt Verbotenes zu suchen. Eine Liste verbotener Muster
	 * laesst Umschreibungen durch - "evil.example" wird in jedem Mailprogramm
	 * ein Link, auch ohne "https://". Ohne Punkt, Schraegstrich und Ziffern
	 * bleibt davon kein Link und keine Telefonnummer.
	 */
	private const NICHT_IM_NAMEN = "/[^\\p{L}\\p{M} '\u{2019}-]/u";

	/** Leerzeichen jeder Art und Zeilenumbrueche. Sie werden zu einem Leerzeichen. */
	private const TRENNER = '/[\\p{Z}\\r\\n\\t]+/u';

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
	 *
	 * Die Angaben aus der Abgabe tippt ein Fremder ins oeffentliche
	 * Formular. Sie gehen deshalb nur gefiltert und gekuerzt in die Mail,
	 * siehe ausDerAbgabe().
	 */
	public function fuer(Empfaenger $empfaenger): self {
		$werte = [
			'{anrede}' => self::ausDerAbgabe($empfaenger->anrede),
			'{vorname}' => self::ausDerAbgabe($empfaenger->vorname),
			'{nachname}' => self::ausDerAbgabe($empfaenger->nachname),
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
	 * Ein Name aus der Abgabe, so wie er in die Mail darf: nur die Zeichen
	 * aus NICHT_IM_NAMEN, einzeilig, hoechstens HOECHSTLAENGE Zeichen.
	 *
	 * Oeffentlich, weil derselbe Name auch im Empfaengerfeld der Mail steht.
	 *
	 * Ungueltiges UTF-8 laesst sich nicht pruefen. preg_replace gibt dann null
	 * zurueck, und der Wert faellt ganz weg.
	 */
	public static function ausDerAbgabe(string $wert): string {
		$einzeilig = (string)preg_replace(self::TRENNER, ' ', $wert);
		$gefiltert = (string)preg_replace(self::NICHT_IM_NAMEN, '', $einzeilig);
		$ohneDoppelte = self::ohneDoppelteLeerzeichen($gefiltert);
		return trim(mb_substr($ohneDoppelte, 0, self::HOECHSTLAENGE));
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
