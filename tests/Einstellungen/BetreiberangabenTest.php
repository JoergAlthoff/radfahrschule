<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Einstellungen;

use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Die Angaben, die den Betreiber der App beschreiben: Name im
 * Formulartitel, Kursart, Aufbewahrungsfrist, Zeitzone.
 */
class BetreiberangabenTest extends TestCase {
	/** @param array<string, string> $werte */
	private function konfigMit(array $werte): IAppConfig {
		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $schluessel, string $vorgabe = '')
					=> $werte[$schluessel] ?? $vorgabe,
			);
		return $konfig;
	}

	/** @param array<string, string> $werte */
	private function angabenMit(array $werte): Betreiberangaben {
		return new Betreiberangaben($this->konfigMit($werte));
	}

	public function testDerNameWirdZumPraefixMitTrenner(): void {
		$angaben = $this->angabenMit(['name' => 'Radfahrschule Musterstadt']);

		// Das Feld traegt nur den Namen. Den Gedankenstrich haengt die App
		// an - er steht auf keiner Tastatur an einer Stelle, die man findet.
		$this->assertSame('Radfahrschule Musterstadt — ', $angaben->titelPraefix());
	}

	public function testEinLeererNameGibtKeinenTrenner(): void {
		// Sonst hiesse ein Formular " — Anfaengerkurs 12.09.2029", und der
		// Titel saehe nach einem Fehler aus statt nach einer fehlenden
		// Einstellung.
		$this->assertSame('', $this->angabenMit([])->titelPraefix());
	}

	public function testDieKursartBekommtIhrTrennzeichen(): void {
		$angaben = $this->angabenMit(['kursart' => 'Anfängerkurs']);

		// Dahinter folgt der Termin. Ohne das Leerzeichen klebte er an der
		// Art: "Anfaengerkurs12.09.2029".
		$this->assertSame('Anfängerkurs ', $angaben->kursart());
	}

	public function testEineLeereKursartGibtKeinLeerzeichen(): void {
		$this->assertSame('', $this->angabenMit([])->kursart());
	}

	public function testDerNameWirdAnDenRaendernBeschnitten(): void {
		// Ein abgetipptes Feld traegt leicht ein Leerzeichen am Ende. Es
		// stuende dann im Titel jedes Formulars, und die Kennung liesse
		// sich nicht mehr paaren.
		$angaben = $this->angabenMit([
			'name' => '  Radfahrschule Musterstadt  ',
			'kursart' => '  Anfängerkurs  ',
		]);

		$this->assertSame('Radfahrschule Musterstadt — ', $angaben->titelPraefix());
		$this->assertSame('Anfängerkurs ', $angaben->kursart());
	}

	public function testDieFristHatEineVorbelegung(): void {
		// Ohne Zahl koennte die Uebersicht keine Frist zeigen. 90 Tage sind
		// die uebliche Vorgabe; sie steht im Formular und bindet den
		// Betreiber.
		$this->assertSame(90, $this->angabenMit([])->aufbewahrungTage());
	}

	public function testDieFristLaesstSichAendern(): void {
		$this->assertSame(
			30, $this->angabenMit(['aufbewahrung_tage' => '30'])->aufbewahrungTage());
	}

	public function testEineUnbrauchbareFristFaelltAufDieVorbelegungZurueck(): void {
		// Null Tage hiesse: sofort loeschen. Ein leeres oder krummes Feld
		// darf keine Frist erfinden, die niemand gewollt hat.
		$this->assertSame(90, $this->angabenMit(['aufbewahrung_tage' => '0'])->aufbewahrungTage());
		$this->assertSame(90, $this->angabenMit(['aufbewahrung_tage' => '-5'])->aufbewahrungTage());
		$this->assertSame(90, $this->angabenMit(['aufbewahrung_tage' => 'viele'])->aufbewahrungTage());
	}

	public function testDieZeitzoneHatEineVorbelegung(): void {
		// Ohne Zone laesst sich nicht sagen, welcher Kalendertag gerade
		// ist. Eine leere Vorgabe waere schlimmer als eine unpassende:
		// Sie faellt niemandem auf, rechnet aber falsch.
		$this->assertSame('Europe/Berlin', $this->angabenMit([])->zeitzone());
	}

	public function testDieZeitzoneLaesstSichAendern(): void {
		$this->assertSame(
			'Europe/Vienna', $this->angabenMit(['zeitzone' => 'Europe/Vienna'])->zeitzone());
	}

	public function testEineUnbekannteZeitzoneFaelltAufDieVorbelegungZurueck(): void {
		// Ein Tippfehler im Feld darf keine Ausnahme werfen, waehrend
		// jemand die Uebersicht aufruft.
		$this->assertSame(
			'Europe/Berlin', $this->angabenMit(['zeitzone' => 'Mars/Olympus'])->zeitzone());
	}

	public function testDerTerminportalHinweisIstFreiwillig(): void {
		$this->assertSame('', $this->angabenMit([])->terminportalHinweis());
		$this->assertSame(
			'Bitte auch ins Terminportal eintragen.',
			$this->angabenMit(['terminportal_hinweis' => 'Bitte auch ins Terminportal eintragen.'])
				->terminportalHinweis());
	}

	public function testVollstaendigVerlangtNamenUndKursart(): void {
		// Frist und Zeitzone haben eine Vorbelegung, die beiden anderen
		// nicht. Ohne sie darf kein Formular entstehen.
		$this->assertFalse($this->angabenMit([])->sindVollstaendig());
		$this->assertFalse($this->angabenMit(['name' => 'Radfahrschule Musterstadt'])->sindVollstaendig());
		$this->assertFalse($this->angabenMit(['kursart' => 'Anfängerkurs'])->sindVollstaendig());
		$this->assertTrue($this->angabenMit([
			'name' => 'Radfahrschule Musterstadt',
			'kursart' => 'Anfängerkurs',
		])->sindVollstaendig());
	}

	public function testSchreibenLegtDieWerteAb(): void {
		$geschrieben = [];
		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $schluessel, string $wert) use (&$geschrieben) {
					$geschrieben[$schluessel] = $wert;
					return true;
				},
			);

		$angaben = new Betreiberangaben($konfig);
		$angaben->setzeName('Radfahrschule Musterstadt');
		$angaben->setzeKursart('Anfängerkurs');
		$angaben->setzeAufbewahrungTage(120);
		$angaben->setzeZeitzone('Europe/Vienna');
		$angaben->setzeTerminportalHinweis('Bitte auch ins Terminportal.');

		$this->assertSame([
			'name' => 'Radfahrschule Musterstadt',
			'kursart' => 'Anfängerkurs',
			'aufbewahrung_tage' => '120',
			'zeitzone' => 'Europe/Vienna',
			'terminportal_hinweis' => 'Bitte auch ins Terminportal.',
		], $geschrieben);
	}

	public function testGeschriebenWirdOhneRandleerzeichen(): void {
		$geschrieben = [];
		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('setValueString')
			->willReturnCallback(
				static function (string $app, string $schluessel, string $wert) use (&$geschrieben) {
					$geschrieben[$schluessel] = $wert;
					return true;
				},
			);

		(new Betreiberangaben($konfig))->setzeName('  Radfahrschule Musterstadt  ');

		$this->assertSame('Radfahrschule Musterstadt', $geschrieben['name']);
	}

	public function testDieAntwortadresseWirdGetrimmtGelesen(): void {
		$angaben = $this->angabenMit(['antwortadresse' => ' kurse@example.org ']);

		$this->assertSame('kurse@example.org', $angaben->antwortadresse());
	}

	/** Ohne Eintrag gibt es keine. Eine Vorbelegung stuende im Code. */
	public function testOhneEintragIstDieAntwortadresseLeer(): void {
		$this->assertSame('', $this->angabenMit([])->antwortadresse());
	}

	public function testDieAbsagetexteWerdenGelesen(): void {
		$angaben = $this->angabenMit([
			'absage_betreff' => ' Der {kursart} am {termin} fällt aus ',
			'absage_text_angemeldete' => "Guten Tag {anrede} {nachname},\nleider fällt der Kurs aus.",
			'absage_text_wartende' => 'Hallo {vorname}, der Kurs fällt aus.',
		]);

		$this->assertSame('Der {kursart} am {termin} fällt aus', $angaben->absageBetreff());
		$this->assertSame("Guten Tag {anrede} {nachname},\nleider fällt der Kurs aus.",
			$angaben->absageTextAngemeldete());
		$this->assertSame('Hallo {vorname}, der Kurs fällt aus.', $angaben->absageTextWartende());
	}

	/** Ohne Eintrag gibt es keine Absage. Eine Vorbelegung stuende im Code. */
	public function testOhneEintragSindDieAbsagetexteLeer(): void {
		$angaben = $this->angabenMit([]);

		$this->assertSame('', $angaben->absageBetreff());
		$this->assertSame('', $angaben->absageTextAngemeldete());
		$this->assertSame('', $angaben->absageTextWartende());
	}

	public function testDieAbsagetexteWerdenAbgelegt(): void {
		$geschrieben = [];
		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $schluessel, string $wert) use (&$geschrieben): bool {
				$geschrieben[$schluessel] = $wert;
				return true;
			});
		$angaben = new Betreiberangaben($konfig);

		$angaben->setzeAbsageBetreff('Absage');
		$angaben->setzeAbsageTextAngemeldete('An Angemeldete');
		$angaben->setzeAbsageTextWartende('An Wartende');

		$this->assertSame([
			'absage_betreff' => 'Absage',
			'absage_text_angemeldete' => 'An Angemeldete',
			'absage_text_wartende' => 'An Wartende',
		], $geschrieben);
	}

	public function testDieTexteDerVerschiebungWerdenGelesen(): void {
		$angaben = $this->angabenMit([
			'verschiebung_betreff' => ' Der {kursart} am {termin} ist verschoben ',
			'verschiebung_text_angemeldete' => "Guten Tag {anrede} {nachname},\nneuer Termin: {neuer_termin}.",
			'verschiebung_text_wartende' => 'Hallo {vorname}, neuer Termin: {neuer_termin}.',
		]);

		$this->assertSame('Der {kursart} am {termin} ist verschoben', $angaben->verschiebungBetreff());
		$this->assertSame("Guten Tag {anrede} {nachname},\nneuer Termin: {neuer_termin}.",
			$angaben->verschiebungTextAngemeldete());
		$this->assertSame('Hallo {vorname}, neuer Termin: {neuer_termin}.', $angaben->verschiebungTextWartende());
	}

	/** Ohne Eintrag gibt es keine Nachricht. Eine Vorbelegung stuende im Code. */
	public function testOhneEintragSindDieTexteDerVerschiebungLeer(): void {
		$angaben = $this->angabenMit([]);

		$this->assertSame('', $angaben->verschiebungBetreff());
		$this->assertSame('', $angaben->verschiebungTextAngemeldete());
		$this->assertSame('', $angaben->verschiebungTextWartende());
	}

	public function testDieTexteDerVerschiebungWerdenAbgelegt(): void {
		$geschrieben = [];
		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $schluessel, string $wert) use (&$geschrieben): bool {
				$geschrieben[$schluessel] = $wert;
				return true;
			});
		$angaben = new Betreiberangaben($konfig);

		$angaben->setzeVerschiebungBetreff('Verschoben');
		$angaben->setzeVerschiebungTextAngemeldete('An Angemeldete');
		$angaben->setzeVerschiebungTextWartende('An Wartende');

		$this->assertSame([
			'verschiebung_betreff' => 'Verschoben',
			'verschiebung_text_angemeldete' => 'An Angemeldete',
			'verschiebung_text_wartende' => 'An Wartende',
		], $geschrieben);
	}
}
