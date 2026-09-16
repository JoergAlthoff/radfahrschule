<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Settings;

use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Settings\Einstellungsbereich;
use OCA\Radfahrschule\Settings\Verwaltung;
use OCP\IURLGenerator;
use OCP\Mail\IEmailValidator;
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

	private function seite(
		string $appPasswort,
		?Formulare $formulare = null,
		bool $vollstaendig = true,
		bool $adresseGueltig = true,
		string $antwortadresse = 'kurse@example.org',
	): Verwaltung {
		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');
		$zugangsdaten->method('benutzer')->willReturn('radfahrschule');
		$zugangsdaten->method('appPasswort')->willReturn($appPasswort);
		$zugangsdaten->method('freigabeGruppe')->willReturn('Radfahrschule');
		$zugangsdaten->method('sindVollstaendig')->willReturn($vollstaendig);

		$emailValidator = $this->createStub(IEmailValidator::class);
		$emailValidator->method('isValid')->willReturn($adresseGueltig);

		return new Verwaltung(
			$zugangsdaten,
			$this->betreiberangaben($antwortadresse),
			$formulare ?? $this->formulareDieAntworten(),
			$emailValidator,
		);
	}

	private function formulareDieAntworten(): Formulare {
		$formulare = $this->createStub(Formulare::class);
		$formulare->method('alleEigenen')->willReturn([]);
		return $formulare;
	}

	private function formulareDieScheitern(string $meldung): Formulare {
		$formulare = $this->createStub(Formulare::class);
		$formulare->method('alleEigenen')->willThrowException(
			new FormulareNichtErreichbar($meldung));
		return $formulare;
	}

	private function betreiberangaben(string $antwortadresse = 'kurse@example.org'): Betreiberangaben {
		$angaben = $this->createStub(Betreiberangaben::class);
		$angaben->method('name')->willReturn('Radfahrschule Musterstadt');
		$angaben->method('kursartRoh')->willReturn('Anfängerkurs');
		$angaben->method('aufbewahrungTage')->willReturn(90);
		$angaben->method('zeitzone')->willReturn('Europe/Berlin');
		$angaben->method('terminportalHinweis')->willReturn('Auch ins Terminportal eintragen.');
		$angaben->method('antwortadresse')->willReturn($antwortadresse);
		$angaben->method('absageBetreff')->willReturn('Der {kursart} fällt aus');
		$angaben->method('absageTextAngemeldete')->willReturn('An Angemeldete');
		$angaben->method('absageTextWartende')->willReturn('An Wartende');
		$angaben->method('verschiebungBetreff')->willReturn('Der {kursart} ist verschoben');
		$angaben->method('verschiebungTextAngemeldete')->willReturn('Neu für Angemeldete');
		$angaben->method('verschiebungTextWartende')->willReturn('Neu für Wartende');

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

	/**
	 * Die Seite sagt, ob der Zugang wirklich funktioniert.
	 *
	 * Ohne diese Auskunft laesst sich ein Tippfehler im Kontonamen nur
	 * daran erkennen, dass die Kursliste leer bleibt - auf einer anderen
	 * Seite, und ohne zu sagen, welches der vier Felder schuld ist.
	 */
	public function testEinFunktionierenderZugangWirdGemeldet(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertSame('', $daten['zugangsfehler']);
		$this->assertTrue($daten['zugangGeprueft']);
	}

	/**
	 * Scheitert der Zugang, steht der Grund auf DIESER Seite.
	 *
	 * Weitergegeben wird die Meldung der Anbindung, nicht eine eigene: Die
	 * dort unterscheidet nach HTTP-Status, ob Konto, Passwort oder Adresse
	 * gemeint ist. Ein eigener Satz hier verloere genau das wieder.
	 */
	public function testEinGescheiterterZugangNenntDenGrundAufDerSeite(): void {
		$daten = $this->seite('geheim',
			$this->formulareDieScheitern('Das Dienstkonto stimmt nicht.'))
			->getForm()->getParams();

		$this->assertSame('Das Dienstkonto stimmt nicht.', $daten['zugangsfehler']);
		$this->assertTrue($daten['zugangGeprueft']);
	}

	/**
	 * Sind die Felder noch leer, wird nichts geprueft und nichts gemeldet.
	 *
	 * Bei einer frisch installierten App ist das der normale Zustand. Eine
	 * Warnung waere dort keine Auskunft, sondern Laerm - dass die Felder
	 * leer sind, sieht man daneben selbst.
	 */
	public function testOhneEingetrageneZugangsdatenWirdNichtGeprueft(): void {
		$formulare = $this->createStub(Formulare::class);
		$formulare->method('alleEigenen')->willThrowException(
			new FormulareNichtErreichbar('Die Zugangsdaten sind unvollständig.'));

		$daten = $this->seite('', $formulare, vollstaendig: false)
			->getForm()->getParams();

		$this->assertSame('', $daten['zugangsfehler']);
		$this->assertFalse($daten['zugangGeprueft'],
			'Ohne Zugangsdaten darf gar kein Aufruf hinausgehen.');
	}

	public function testDieAntwortadresseStehtAufDerSeite(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertSame('kurse@example.org', $daten['antwortadresse']);
		$this->assertFalse($daten['antwortadresseUngueltig']);
	}

	/**
	 * Eine ungueltige Antwortadresse laesst der Versand weg. Ohne Warnung
	 * merkte das niemand - Antworten landeten still beim Hoster.
	 */
	public function testEineUngueltigeAntwortadresseWirdGemeldet(): void {
		$daten = $this->seite('geheim', adresseGueltig: false)->getForm()->getParams();

		$this->assertTrue($daten['antwortadresseUngueltig']);
	}

	/**
	 * Eine leere Antwortadresse ist kein Fehler - sie ist die Vorbelegung.
	 * Nur eine EINGETRAGENE, aber ungueltige Adresse ist eine Luecke.
	 */
	public function testEineLeereAntwortadresseWirdNichtGemeldet(): void {
		$daten = $this->seite('geheim', adresseGueltig: false, antwortadresse: '')->getForm()->getParams();

		$this->assertFalse($daten['antwortadresseUngueltig']);
	}

	public function testDieAbsagetexteStehenAufDerSeite(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertSame('Der {kursart} fällt aus', $daten['absageBetreff']);
		$this->assertSame('An Angemeldete', $daten['absageTextAngemeldete']);
		$this->assertSame('An Wartende', $daten['absageTextWartende']);
	}

	public function testDieTexteDerVerschiebungStehenAufDerSeite(): void {
		$daten = $this->seite('geheim')->getForm()->getParams();

		$this->assertSame('Der {kursart} ist verschoben', $daten['verschiebungBetreff']);
		$this->assertSame('Neu für Angemeldete', $daten['verschiebungTextAngemeldete']);
		$this->assertSame('Neu für Wartende', $daten['verschiebungTextWartende']);
	}
}
