<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\Eingabe;
use PHPUnit\Framework\TestCase;

final class EingabeTest extends TestCase {
	/**
	 * Ein Kurs, den es in den Testdaten NICHT gibt. Der Termin liegt fest
	 * und in der Zukunft der Testuhr - nie die echte Zeit benutzen, sonst
	 * ist der Test ab einem bestimmten Tag rot, ohne dass sich eine Zeile
	 * aendert.
	 */
	private function gueltig(): Eingabe {
		$zone = new DateTimeZone('UTC');
		return new Eingabe(
			vorlageAnmeldung: 23,
			vorlageWarteliste: 24,
			von: new DateTimeImmutable('2026-10-10', $zone),
			bis: new DateTimeImmutable('2026-10-11', $zone),
			anmeldeschluss: new DateTimeImmutable('2026-10-03', $zone),
			plaetze: 6,
		);
	}

	private function jetzt(string $zeitpunkt = '2026-09-01 10:00:00'): DateTimeImmutable {
		return new DateTimeImmutable($zeitpunkt, new DateTimeZone('UTC'));
	}

	public function testGueltigeAngabenGehenDurch(): void {
		$this->assertNull($this->gueltig()->pruefe($this->jetzt()));
	}

	public function testOhneVorlageFuerDieAnmeldung(): void {
		$eingabe = $this->gueltig()->mitVorlagen(0, 24);

		$this->assertStringContainsString('Anmeldung', (string)$eingabe->pruefe($this->jetzt()));
	}

	public function testOhneVorlageFuerDieWarteliste(): void {
		$eingabe = $this->gueltig()->mitVorlagen(23, 0);

		$this->assertStringContainsString('Warteliste', (string)$eingabe->pruefe($this->jetzt()));
	}

	/**
	 * Waeren beide dasselbe Formular, entstuenden zwei Klone derselben
	 * Vorlage - und der Kurs haette zwei Anmeldungen oder zwei Wartelisten.
	 */
	public function testDieselbeVorlageZweimalWirdAbgelehnt(): void {
		$eingabe = $this->gueltig()->mitVorlagen(23, 23);

		$this->assertNotNull($eingabe->pruefe($this->jetzt()));
	}

	public function testLetzterTagVorDemErsten(): void {
		$eingabe = $this->gueltig()->mitTagen('2026-10-11', '2026-10-10');

		$this->assertNotNull($eingabe->pruefe($this->jetzt()));
	}

	public function testAnmeldeschlussNachDemKursbeginn(): void {
		$eingabe = $this->gueltig()->mitAnmeldeschluss('2026-10-11');

		$this->assertNotNull($eingabe->pruefe($this->jetzt()));
	}

	/**
	 * Der Anmeldeschluss darf auf dem ersten Kurstag liegen - wer morgens
	 * kommt, soll sich fuer den Abend noch anmelden koennen. Verglichen
	 * wird deshalb mit > und nicht mit >=.
	 *
	 * Der Test steht genau auf dieser Grenze. Ohne ihn fiele eine
	 * Verschiebung um einen Tag nicht auf: Der Nachbartest oben nimmt den
	 * Tag DANACH und bliebe gruen.
	 */
	public function testAnmeldeschlussAmErstenKurstagGehtDurch(): void {
		$eingabe = $this->gueltig()->mitAnmeldeschluss('2026-10-10');

		$this->assertNull($eingabe->pruefe($this->jetzt()));
	}

	/**
	 * Faengt den Tippfehler im Jahr ab. Ohne die Pruefung entstuende ein
	 * Formularpaar, dessen expires in der Vergangenheit liegt: sofort
	 * geschlossen, und niemand kann sich anmelden.
	 */
	public function testKursInDerVergangenheitWirdAbgelehnt(): void {
		$eingabe = $this->gueltig()->mitTagen('2025-10-10', '2025-10-11');

		$this->assertNotNull($eingabe->pruefe($this->jetzt()));
	}

	/**
	 * Der Kurstag selbst zaehlt noch: Wer morgens einen Kurs fuer denselben
	 * Abend anlegt, soll das koennen. Die Uhrzeit im Testdatum ist hier
	 * funktionaler Teil - mit einem Vergleich auf Zeitpunkte waere der
	 * heutige Kurs ab 00:01 abgelehnt.
	 */
	public function testEinKursAmHeutigenTagGehtDurch(): void {
		$eingabe = $this->gueltig()->mitTagen('2026-09-01', '2026-09-01')
			->mitAnmeldeschluss('2026-08-25');

		$this->assertNull($eingabe->pruefe($this->jetzt('2026-09-01 18:30:00')));
	}

	public function testNullPlaetzeWerdenAbgelehnt(): void {
		$eingabe = $this->gueltig()->mitPlaetzen(0);

		$this->assertNotNull($eingabe->pruefe($this->jetzt()));
	}
}
