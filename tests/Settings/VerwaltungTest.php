<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Settings;

use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Settings\Einstellungsbereich;
use OCA\Radfahrschule\Settings\Verwaltung;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

final class VerwaltungTest extends TestCase {
	/**
	 * Beide Kennungen muessen gleich sein. Weichen sie ab, steht der Eintrag
	 * in der linken Spalte und die Seite dahinter bleibt leer - ohne Fehler.
	 */
	public function testDieSeiteLiegtImEigenenBereich(): void {
		$bereich = new Einstellungsbereich($this->createStub(IURLGenerator::class));

		$this->assertSame($bereich->getID(), $this->seite('geheim')->getSection());
	}

	private function seite(string $appPasswort): Verwaltung {
		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');
		$zugangsdaten->method('benutzer')->willReturn('radfahrschule');
		$zugangsdaten->method('appPasswort')->willReturn($appPasswort);
		$zugangsdaten->method('freigabeGruppe')->willReturn('Radfahrschule');

		return new Verwaltung($zugangsdaten, $this->betreiberangaben());
	}

	private function betreiberangaben(): Betreiberangaben {
		$angaben = $this->createStub(Betreiberangaben::class);
		$angaben->method('name')->willReturn('Radfahrschule Musterstadt');
		$angaben->method('kursartRoh')->willReturn('Anfängerkurs');
		$angaben->method('aufbewahrungTage')->willReturn(90);
		$angaben->method('zeitzone')->willReturn('Europe/Berlin');
		$angaben->method('terminportalHinweis')->willReturn('Auch ins Terminportal eintragen.');

		return $angaben;
	}

	/**
	 * Das Passwort selbst darf die Seite nie erreichen - nur die Auskunft,
	 * ob eines hinterlegt ist.
	 */
	public function testEinGesetztesPasswortWirdGemeldetAberNichtGezeigt(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertTrue($daten['passwortGesetzt']);
		$this->assertNotContains('geheim', $daten);
	}

	public function testOhnePasswortMeldetDieSeiteEineLuecke(): void {
		$daten = $this->seite('')->getForm()->getParams();

		$this->assertFalse($daten['passwortGesetzt']);
	}

	public function testDieUebrigenAngabenStehenAufDerSeite(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertSame('einstellungen', $this->seite('geheim')->getForm()->getTemplateName());
		$this->assertSame('https://cloud.example.org', $daten['basisUrl']);
		$this->assertSame('radfahrschule', $daten['benutzer']);
		$this->assertSame('Radfahrschule', $daten['freigabeGruppe']);
	}

	/**
	 * Ohne diese fuenf Felder liesse sich nichts eintragen, und die App
	 * legte nichts an - sie kennt dann weder Namen noch Kursart.
	 */
	public function testDieAngabenDesBetreibersStehenAufDerSeite(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertSame('Radfahrschule Musterstadt', $daten['name']);
		$this->assertSame('Anfängerkurs', $daten['kursart']);
		$this->assertSame(90, $daten['aufbewahrungTage']);
		$this->assertSame('Europe/Berlin', $daten['zeitzone']);
		$this->assertSame('Auch ins Terminportal eintragen.', $daten['terminportalHinweis']);
	}

	/**
	 * Die Auswahlliste verhindert einen Tippfehler, der sonst still auf die
	 * Vorbelegung zurueckfiele - und niemand saehe, warum die Tage nicht
	 * stimmen.
	 */
	public function testDieZeitzonenListeEnthaeltDieEingestellte(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertContains('Europe/Berlin', $daten['zeitzonen']);
		$this->assertContains('UTC', $daten['zeitzonen']);
	}
}
