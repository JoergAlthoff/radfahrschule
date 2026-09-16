<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Einstellungen;

use DateTimeZone;
use OCP\IAppConfig;

/**
 * Die Angaben, die beschreiben, WER die App betreibt: der Name im
 * Formulartitel, die Kursart, die Aufbewahrungsfrist, die Zeitzone, die
 * Antwortadresse.
 *
 * Sie stehen in der Konfiguration und nicht im Code, weil jede Instanz einen
 * anderen Betreiber hat. Wer die App installiert, traegt seine eigenen
 * Angaben ein und bekommt nicht die eines fremden Vereins in seine Titel.
 *
 * Name und Kursart haben KEINE Vorbelegung. Ein Vorgabewert stuende wieder
 * im Code, nur an einer Stelle statt an vielen. Fehlen sie, sagt die App das
 * und legt nichts an.
 *
 * Frist und Zeitzone haben eine, weil ohne sie gar nichts rechnen kann. Eine
 * leere Zeitzone waere schlimmer als eine unpassende: Sie faellt niemandem
 * auf und rechnet trotzdem falsch.
 *
 * Schwester von Zugangsdaten - die handelt vom Zugang zur Forms-API, diese
 * von der Fachlichkeit, samt der Texte der Absage und der Verschiebung.
 *
 * NICHT final: Tests ersetzen die Klasse durch ein Doppel, und PHPUnit kann
 * eine final-Klasse nicht ersetzen.
 */
readonly class Betreiberangaben {
	private const APP = 'radfahrschule';

	/** Zwischen Name und Kursart im Formulartitel. */
	private const TRENNER = ' — ';

	private const VORGABE_AUFBEWAHRUNG_TAGE = 90;
	private const VORGABE_ZEITZONE = 'Europe/Berlin';

	public function __construct(private IAppConfig $appConfig) {
	}

	/**
	 * Der Anfang jedes Formulartitels, den die App erzeugt.
	 *
	 * Das Eingabefeld traegt nur den Namen; den Trenner haengt diese
	 * Methode an. Wer ihn selbst tippen muesste, braeuchte einen
	 * Gedankenstrich - und den findet man auf keiner Tastatur schnell.
	 *
	 * Ohne Namen bleibt der Trenner weg. Sonst hiesse ein Formular
	 * " — Anfaengerkurs 12.09.2029", und das saehe nach einem Fehler aus
	 * statt nach einer fehlenden Einstellung.
	 */
	public function titelPraefix(): string {
		$name = $this->name();
		return $name === '' ? '' : $name . self::TRENNER;
	}

	/** Der Name ohne Trenner, so wie er im Eingabefeld steht. */
	public function name(): string {
		return $this->getrimmt('name');
	}

	/**
	 * Die Kursart, mit dem Leerzeichen, das der Termin dahinter braucht.
	 *
	 * Ohne es klebte der Termin an der Art: "Anfaengerkurs12.09.2029".
	 */
	public function kursart(): string {
		$kursart = $this->getrimmt('kursart');
		return $kursart === '' ? '' : $kursart . ' ';
	}

	/** Die Kursart ohne Leerzeichen, so wie sie im Eingabefeld steht. */
	public function kursartRoh(): string {
		return $this->getrimmt('kursart');
	}

	/**
	 * Nach wie vielen Tagen die Anmeldedaten geloescht sein muessen.
	 *
	 * Die Zahl steht im Formular und bindet den Betreiber. Ein unbrauchbarer
	 * Wert faellt auf die Vorbelegung zurueck: Null Tage hiesse "sofort
	 * loeschen", und das hat niemand gewollt, der ein Feld leer laesst.
	 */
	public function aufbewahrungTage(): int {
		$eingetragen = (int)$this->getrimmt('aufbewahrung_tage');
		return $eingetragen > 0 ? $eingetragen : self::VORGABE_AUFBEWAHRUNG_TAGE;
	}

	/**
	 * Die Zeitzone, in der die Kurse stattfinden.
	 *
	 * Sie beantwortet, welcher Kalendertag gerade ist. NICHT die
	 * Servereinstellung: Die kann ein Admin aendern, und dann verschoeben
	 * sich Kurstermine, obwohl die Kurse weiter am selben Ort stattfinden.
	 *
	 * Ein Tippfehler im Feld faellt auf die Vorbelegung zurueck. Er darf
	 * keine Ausnahme werfen, waehrend jemand die Uebersicht aufruft.
	 */
	public function zeitzone(): string {
		$eingetragen = $this->getrimmt('zeitzone');
		if ($eingetragen === '' || !in_array($eingetragen, DateTimeZone::listIdentifiers(), true)) {
			return self::VORGABE_ZEITZONE;
		}
		return $eingetragen;
	}

	/**
	 * Ein freiwilliger Satz, der nach dem Anlegen und Verschieben erscheint.
	 *
	 * Gedacht fuer den Hinweis, dass der Termin auch anderswo eingetragen
	 * werden muss - etwa in einem Terminportal. Bleibt das Feld leer,
	 * entfaellt der Absatz.
	 */
	public function terminportalHinweis(): string {
		return $this->getrimmt('terminportal_hinweis');
	}

	/**
	 * Wohin Antworten auf eine Nachricht an Teilnehmer gehen.
	 *
	 * Nextcloud verschickt unter seiner eigenen Absenderadresse, und die
	 * gehoert bei einer gehosteten Instanz dem Hoster. Wer auf "Antworten"
	 * klickt, schreibt an diese Adresse. Leer heisst: keine Antwortadresse.
	 */
	public function antwortadresse(): string {
		return $this->getrimmt('antwortadresse');
	}

	/**
	 * Der vorbelegte Betreff einer Absage. Er gilt fuer beide Listen.
	 *
	 * Leer heisst: Die Nachfrage vor dem Loeschen zeigt ein leeres Feld.
	 */
	public function absageBetreff(): string {
		return $this->getrimmt('absage_betreff');
	}

	/** Der vorbelegte Text der Absage an die Angemeldeten. */
	public function absageTextAngemeldete(): string {
		return $this->getrimmt('absage_text_angemeldete');
	}

	/**
	 * Der vorbelegte Text der Absage an die Warteliste.
	 *
	 * Ein eigener Text, weil es um etwas anderes geht: Wer angemeldet war,
	 * verliert einen Platz, wer wartet, eine Aussicht.
	 */
	public function absageTextWartende(): string {
		return $this->getrimmt('absage_text_wartende');
	}

	/**
	 * Der vorbelegte Betreff der Nachricht beim Verschieben. Er gilt fuer
	 * beide Listen.
	 *
	 * Leer heisst: Die Kontrollseite zeigt ein leeres Feld.
	 */
	public function verschiebungBetreff(): string {
		return $this->getrimmt('verschiebung_betreff');
	}

	/** Der vorbelegte Text an die Angemeldeten, wenn ein Kurs verschoben wird. */
	public function verschiebungTextAngemeldete(): string {
		return $this->getrimmt('verschiebung_text_angemeldete');
	}

	/**
	 * Der vorbelegte Text an die Warteliste, wenn ein Kurs verschoben wird.
	 *
	 * Ein eigener Text, weil es um etwas anderes geht: Wer angemeldet ist,
	 * behaelt seinen Platz, wer wartet, nur die Aussicht darauf.
	 */
	public function verschiebungTextWartende(): string {
		return $this->getrimmt('verschiebung_text_wartende');
	}

	/**
	 * Reicht das, um ein Formular anzulegen?
	 *
	 * Nur Name und Kursart - Frist und Zeitzone haben eine Vorbelegung.
	 */
	public function sindVollstaendig(): bool {
		return $this->name() !== '' && $this->kursartRoh() !== '';
	}

	public function setzeName(string $wert): void {
		$this->schreibe('name', trim($wert));
	}

	public function setzeKursart(string $wert): void {
		$this->schreibe('kursart', trim($wert));
	}

	public function setzeAufbewahrungTage(int $wert): void {
		$this->schreibe('aufbewahrung_tage', (string)$wert);
	}

	public function setzeZeitzone(string $wert): void {
		$this->schreibe('zeitzone', trim($wert));
	}

	public function setzeTerminportalHinweis(string $wert): void {
		$this->schreibe('terminportal_hinweis', trim($wert));
	}

	public function setzeAntwortadresse(string $wert): void {
		$this->schreibe('antwortadresse', trim($wert));
	}

	public function setzeAbsageBetreff(string $wert): void {
		$this->schreibe('absage_betreff', trim($wert));
	}

	public function setzeAbsageTextAngemeldete(string $wert): void {
		$this->schreibe('absage_text_angemeldete', trim($wert));
	}

	public function setzeAbsageTextWartende(string $wert): void {
		$this->schreibe('absage_text_wartende', trim($wert));
	}

	public function setzeVerschiebungBetreff(string $wert): void {
		$this->schreibe('verschiebung_betreff', trim($wert));
	}

	public function setzeVerschiebungTextAngemeldete(string $wert): void {
		$this->schreibe('verschiebung_text_angemeldete', trim($wert));
	}

	public function setzeVerschiebungTextWartende(string $wert): void {
		$this->schreibe('verschiebung_text_wartende', trim($wert));
	}

	/**
	 * Gelesen wird beschnitten.
	 *
	 * Ein abgetipptes Feld traegt leicht ein Leerzeichen am Ende. Es stuende
	 * dann im Titel jedes Formulars, und die Kennung liesse sich nicht mehr
	 * paaren.
	 */
	private function getrimmt(string $schluessel): string {
		return trim($this->appConfig->getValueString(self::APP, $schluessel, ''));
	}

	private function schreibe(string $schluessel, string $wert): void {
		$this->appConfig->setValueString(self::APP, $schluessel, $wert);
	}
}
