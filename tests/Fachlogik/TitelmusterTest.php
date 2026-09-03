<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\Formularart;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

/**
 * Baut und zerlegt Formulartitel. Der Anfang jedes Anmeldetitels kommt aus
 * den Betreiberangaben.
 */
class TitelmusterTest extends TestCase {
	use MitTitelmuster;

	private function tag(string $iso): DateTimeImmutable {
		return new DateTimeImmutable($iso . ' 00:00:00', new DateTimeZone('UTC'));
	}

	public function testDerAnmeldetitelTraegtNamenArtUndTermin(): void {
		$this->assertSame(
			'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2029',
			$this->titelmuster()->fuerAnmeldung($this->tag('2029-09-12'), $this->tag('2029-09-13')));
	}

	public function testDerWartelistentitelTraegtDenselbenRest(): void {
		// Der gemeinsame Rest ist die einzige Klammer zwischen beiden
		// Formularen - Nextcloud kennt keine Kurse.
		$this->assertSame(
			'Warteliste — Anfängerkurs 12./13.09.2029',
			$this->titelmuster()->fuerWarteliste($this->tag('2029-09-12'), $this->tag('2029-09-13')));
	}

	public function testEinAndererBetreiberBekommtAndereTitel(): void {
		// Der eigentliche Zweck: Wer die App installiert, bekommt seinen
		// eigenen Namen in die Titel, nicht einen fremden.
		$muster = $this->titelmuster(['name' => 'Radfahrschule Bochum', 'kursart' => 'Fortgeschrittene']);

		$this->assertSame(
			'Radfahrschule Bochum — Fortgeschrittene 12./13.09.2029',
			$muster->fuerAnmeldung($this->tag('2029-09-12'), $this->tag('2029-09-13')));
	}

	public function testZerlegenErkenntDieAnmeldung(): void {
		$zerlegt = $this->titelmuster()->zerlege('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2029');

		$this->assertSame(Formularart::Anmeldung, $zerlegt->art);
		$this->assertSame('Anfängerkurs 12./13.09.2029', $zerlegt->kennung);
	}

	public function testZerlegenErkenntDieWarteliste(): void {
		$zerlegt = $this->titelmuster()->zerlege('Warteliste — Anfängerkurs 12./13.09.2029');

		$this->assertSame(Formularart::Warteliste, $zerlegt->art);
		$this->assertSame('Anfängerkurs 12./13.09.2029', $zerlegt->kennung);
	}

	public function testZerlegenErkenntEineVorlage(): void {
		$zerlegt = $this->titelmuster()->zerlege('VORLAGE Anmeldung - Radfahrschule');

		$this->assertSame(Formularart::Vorlage, $zerlegt->art);
		$this->assertSame('', $zerlegt->kennung);
	}

	public function testEinFremderTitelBleibtUnbekannt(): void {
		$zerlegt = $this->titelmuster()->zerlege('Mitgliederbefragung 2029');

		$this->assertSame(Formularart::Unbekannt, $zerlegt->art);
		$this->assertSame('Mitgliederbefragung 2029', $zerlegt->kennung);
	}

	public function testOhneNamenGiltKeinFormularAlsAnmeldung(): void {
		// Ein leerer Name gibt einen leeren Praefix, und
		// str_starts_with($titel, '') ist immer wahr. Ohne Abfangen waere
		// jedes Formular der Instanz eine Anmeldung - auch die
		// Mitgliederbefragung.
		$zerlegt = $this->titelmuster(['name' => ''])->zerlege('Mitgliederbefragung 2029');

		$this->assertSame(Formularart::Unbekannt, $zerlegt->art);
		$this->assertSame('Mitgliederbefragung 2029', $zerlegt->kennung);
	}

	public function testOhneNamenWirdDieWartelisteWeiterErkannt(): void {
		// Ihr Praefix haengt nicht am Betreiber, er ist eine Konstante.
		// Dieser Test haelt fest, dass die beiden Wege getrennt sind.
		$zerlegt = $this->titelmuster(['name' => ''])->zerlege('Warteliste — Anfängerkurs 12./13.09.2029');

		$this->assertSame(Formularart::Warteliste, $zerlegt->art);
	}

	public function testDieVorlageWirdVorDerAnmeldungGeprueft(): void {
		// Traegt der Betreiber "VORLAGE" als Namen ein, faengt jeder
		// Vorlagentitel auch mit seinem Praefix an. Die Vorlage muss zuerst
		// gewinnen, sonst verschwinden die Vorlagen aus der Auswahl und
		// niemand kann mehr einen Kurs anlegen.
		$muster = $this->titelmuster(['name' => 'VORLAGE', 'kursart' => 'Anfängerkurs']);
		$zerlegt = $muster->zerlege('VORLAGE Anmeldung - Radfahrschule');

		$this->assertSame(Formularart::Vorlage, $zerlegt->art);
	}

	public function testDieBeidenPraefixeSindAbfragbar(): void {
		// Verschiebeplan baut daraus die neuen Titel, ohne den Termin
		// noch einmal zu rechnen.
		$muster = $this->titelmuster();

		$this->assertSame('Radfahrschule Musterstadt — ', $muster->praefixAnmeldung());
		$this->assertSame('Warteliste — ', $muster->praefixWarteliste());
	}

	/**
	 * Bauen und Zerlegen sind zwei Haelften derselben Regel. Liefen sie
	 * auseinander, faende die Uebersicht den eben angelegten Kurs nicht.
	 */
	public function testGebauterTitelLaesstSichWiederZerlegen(): void {
		$muster = $this->titelmuster();
		$titel = $muster->fuerAnmeldung($this->tag('2029-09-12'), $this->tag('2029-09-13'));

		$zerlegt = $muster->zerlege($titel);

		$this->assertSame(Formularart::Anmeldung, $zerlegt->art);
		$this->assertSame('Anfängerkurs 12./13.09.2029', $zerlegt->kennung);
	}

	public function testAnmeldungUndWartelisteTeilenDieKennung(): void {
		$muster = $this->titelmuster();
		$von = $this->tag('2029-09-12');
		$bis = $this->tag('2029-09-13');

		$ausAnmeldung = $muster->zerlege($muster->fuerAnmeldung($von, $bis));
		$ausWarteliste = $muster->zerlege($muster->fuerWarteliste($von, $bis));

		$this->assertSame($ausAnmeldung->kennung, $ausWarteliste->kennung);
	}
}
