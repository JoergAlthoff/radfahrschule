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
	 * Was ein Teilnehmer eintippt, wird nicht noch einmal ersetzt. Die
	 * geschweiften Klammern verliert ein Name ohnehin, und strtr ersetzt in
	 * einem Durchgang.
	 */
	public function testEinPlatzhalterImNamenWirdNichtErsetzt(): void {
		$seltsam = new Empfaenger('', '{termin}', 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', 'Hallo {vorname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Hallo termin', $nachricht->fuer($seltsam)->text);
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

	/**
	 * Was im oeffentlichen Formular steht, tippt ein Fremder. Ein langer
	 * Text in einem Namensfeld kaeme sonst unter dem Namen des Vereins bei
	 * jemandem an, dessen Adresse er dazugeschrieben hat.
	 */
	public function testEinLangerNameWirdGekuerzt(): void {
		$lang = new Empfaenger('', str_repeat('ä', 60), 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', '{vorname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame(str_repeat('ä', Nachricht::HOECHSTLAENGE), $nachricht->fuer($lang)->text);
	}

	public function testEinNameGenauAufDerGrenzeBleibtGanz(): void {
		$grenze = new Empfaenger('', str_repeat('ä', Nachricht::HOECHSTLAENGE), 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', '{vorname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame(str_repeat('ä', Nachricht::HOECHSTLAENGE), $nachricht->fuer($grenze)->text);
	}

	/** Buchstaben jeder Sprache, Leerzeichen, Bindestrich und beide Apostrophe bleiben. */
	public function testEchteNamenBleibenUnveraendert(): void {
		$echt = new Empfaenger('', "Marie-Luise O'Neil", 'van ’t Hooft Łukasiewicz', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', '{vorname} {nachname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame("Marie-Luise O'Neil van ’t Hooft Łukasiewicz", $nachricht->fuer($echt)->text);
	}

	/** Ein Akzent als eigenes Zeichen hinter dem Buchstaben gehoert zum Namen. */
	public function testEinNachgestellterAkzentBleibt(): void {
		$zerlegt = new Empfaenger('', "Jose\u{0301}", 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', '{vorname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame("Jose\u{0301}", $nachricht->fuer($zerlegt)->text);
	}

	/**
	 * Ohne Punkt, Schraegstrich und Doppelpunkt ist ein Link keiner mehr.
	 * Mailprogramme machen auch ohne "https://" einen daraus, solange der
	 * Punkt steht.
	 */
	public function testEinLinkVerliertSeineSatzzeichen(): void {
		$mitLink = new Empfaenger('', 'https://evil.example/login', 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Für {vorname}', 'Hallo {vorname}', 'Anfängerkurs', '12.09.2026');

		$fuerIhn = $nachricht->fuer($mitLink);

		$this->assertSame('Für httpsevilexamplelogin', $fuerIhn->betreff);
		$this->assertSame('Hallo httpsevilexamplelogin', $fuerIhn->text);
	}

	/** Ziffern gehoeren in keinen Namen. So faellt auch eine Telefonnummer weg. */
	public function testZiffernFallenWeg(): void {
		$mitNummer = new Empfaenger('', 'Rückruf 0151 1234567', 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', 'Hallo {vorname}!', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Hallo Rückruf!', $nachricht->fuer($mitNummer)->text);
	}

	/**
	 * Zeichen ohne Breite und Richtungswechsel sind keine Buchstaben. Mit
	 * ihnen liesse sich ein Text tarnen oder rueckwaerts lesen.
	 */
	public function testUnsichtbareUndSteuerndeZeichenFallenWeg(): void {
		$getarnt = new Empfaenger("\u{202E}Frau", "Eri\u{200B}ka", 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', '{anrede} {vorname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Frau Erika', $nachricht->fuer($getarnt)->text);
	}

	/** Auch die Unicode-Zeilentrenner beenden keinen Betreff. */
	public function testEinUnicodeZeilentrennerErreichtDenBetreffNicht(): void {
		$mitTrenner = new Empfaenger('', "Erika\u{2028}Bcc", 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Für {vorname}', 'Text', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Für Erika Bcc', $nachricht->fuer($mitTrenner)->betreff);
	}

	/** Ungueltiges UTF-8 laesst sich nicht pruefen. Es kommt gar nicht hinein. */
	public function testUngueltigesUtf8FaelltGanzWeg(): void {
		$kaputt = new Empfaenger('', "Erika\xC3", 'Muster', 'x@example.org');
		$nachricht = new Nachricht('Hinweis', 'Hallo {vorname} {nachname}', 'Anfängerkurs', '12.09.2026');

		$this->assertSame('Hallo Muster', $nachricht->fuer($kaputt)->text);
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
