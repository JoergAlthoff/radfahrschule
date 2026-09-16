<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Benachrichtigung;

use OCA\Radfahrschule\Benachrichtigung\Nachricht;
use OCA\Radfahrschule\Formulare\Empfaenger;
use PHPUnit\Framework\TestCase;

final class NachrichtTest extends TestCase {
	private function erika(): Empfaenger {
		return new Empfaenger('Frau', 'Erika', 'Muster', 'erika.muster@example.org');
	}

	public function testAllePlatzhalterWerdenEingesetzt(): void {
		$nachricht = new Nachricht(
			betreff: 'Der {kursart} am {termin}',
			text: 'Guten Tag {anrede} {nachname}, hallo {vorname}.',
			kursart: 'Anfängerkurs',
			termin: '12./13.09.2026',
		);

		$fuerErika = $nachricht->fuer($this->erika());

		$this->assertSame('Der Anfängerkurs am 12./13.09.2026', $fuerErika->betreff);
		$this->assertSame('Guten Tag Frau Muster, hallo Erika.', $fuerErika->text);
	}

	/**
	 * Die Anrede ist kein Pflichtfeld. Ohne sie bliebe ein doppeltes
	 * Leerzeichen stehen, und die Mail saehe schlampig aus.
	 */
	public function testOhneAnredeBleibtKeinDoppeltesLeerzeichen(): void {
		$ohneAnrede = new Empfaenger('', 'Max', 'Probe', 'max.probe@example.org');
		$nachricht = new Nachricht('Hinweis', 'Guten Tag {anrede} {nachname},', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Guten Tag Probe,', $nachricht->fuer($ohneAnrede)->text);
	}

	/**
	 * Was ein Teilnehmer eintippt, wird nicht noch einmal ersetzt. Sonst
	 * stuende in seiner Mail etwas anderes, als er geschrieben hat.
	 */
	public function testEinPlatzhalterImNamenWirdNichtErsetzt(): void {
		$seltsam = new Empfaenger('', '{termin}', 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', 'Hallo {vorname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Hallo {termin}', $nachricht->fuer($seltsam)->text);
	}

	/**
	 * Ein Zeilenumbruch aus einem Eingabefeld darf nicht in den Betreff.
	 * Dort beendete er die Kopfzeile.
	 */
	public function testEinZeilenumbruchAusDerAbgabeErreichtDenBetreffNicht(): void {
		$mitUmbruch = new Empfaenger('', "Erika\nBcc: fremd@example.org", 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Für {vorname}', 'Text', 'Anfängerkurs', '12.09.2026');

		$betreff = $nachricht->fuer($mitUmbruch)->betreff;

		$this->assertStringNotContainsString("\n", $betreff);
		$this->assertStringNotContainsString("\r", $betreff);
	}

	/**
	 * Nicht nur eine Abgabe kann einen Zeilenumbruch in den Betreff tragen -
	 * auch die Vorlage selbst, wenn sie handgestrickt als POST hereinkommt.
	 */
	public function testEinZeilenumbruchInDerVorlageErreichtDenBetreffNicht(): void {
		$nachricht = new Nachricht("Zeile eins\r\nBcc: fremd@example.org", 'Text', 'Anfängerkurs', '12.09.2026');

		$betreff = $nachricht->fuer($this->erika())->betreff;

		$this->assertStringNotContainsString("\r", $betreff);
		$this->assertStringNotContainsString("\n", $betreff);
	}

	/** Die Zeilen des Textes selbst bleiben erhalten. */
	public function testDieZeilenDesTextesBleiben(): void {
		$nachricht = new Nachricht('Hinweis', "Zeile eins\nZeile zwei", 'Anfängerkurs', '12.09.2026');

		$this->assertSame("Zeile eins\nZeile zwei", $nachricht->fuer($this->erika())->text);
	}

	public function testEinUnbekannterPlatzhalterBleibtStehen(): void {
		$nachricht = new Nachricht('Hinweis', 'Treffpunkt {ort}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Treffpunkt {ort}', $nachricht->fuer($this->erika())->text);
	}

	public function testNurLeerzeichenIstLeer(): void {
		$this->assertTrue((new Nachricht('Hinweis', " \n ", 'Anfängerkurs', ''))->istLeer());
		$this->assertFalse((new Nachricht('Hinweis', 'Text', 'Anfängerkurs', ''))->istLeer());
	}

	public function testDerNeueTerminWirdEingesetzt(): void {
		$nachricht = new Nachricht(
			betreff: 'Der {kursart} am {termin} ist verschoben',
			text: 'Neuer Termin: {neuer_termin}',
			kursart: 'Anfängerkurs',
			termin: '12./13.09.2026',
			neuerTermin: '14./15.11.2026',
		);

		$fuerErika = $nachricht->fuer($this->erika());

		$this->assertSame('Der Anfängerkurs am 12./13.09.2026 ist verschoben', $fuerErika->betreff);
		$this->assertSame('Neuer Termin: 14./15.11.2026', $fuerErika->text);
	}

	/** Ohne Verschiebung gibt es keinen neuen Termin. Die Stelle bleibt leer. */
	public function testOhneNeuenTerminBleibtDieStelleLeer(): void {
		$nachricht = new Nachricht('Hinweis', 'Bisher {termin}, neu {neuer_termin}.', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Bisher 12.09.2026, neu .', $nachricht->fuer($this->erika())->text);
	}
}
