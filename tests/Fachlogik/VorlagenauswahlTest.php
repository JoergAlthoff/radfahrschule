<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use OCA\Radfahrschule\Fachlogik\Formularart;
use OCA\Radfahrschule\Fachlogik\Vorlagenauswahl;
use OCA\Radfahrschule\Fachlogik\Vorlagenpaar;
use OCA\Radfahrschule\Formulare\Formular;
use PHPUnit\Framework\TestCase;

final class VorlagenauswahlTest extends TestCase {
	private function formular(int $id, string $titel): Formular {
		return new Formular(
			id: $id, hash: 'editor' . str_pad((string)$id, 10, '0', STR_PAD_LEFT),
			titel: $titel, beschreibung: '', abgaben: 0, ablauf: 0,
		);
	}

	/**
	 * Nextcloud liefert die Warteliste VOR der Anmeldung. Diese Reihenfolge
	 * ist hier funktionaler Teil des Tests: Genau sie macht eine Vorbelegung
	 * gefaehrlich, die nicht nach der Art trennt.
	 *
	 * @return list<Formular>
	 */
	private function bestand(): array {
		return [
			$this->formular(24, 'VORLAGE Warteliste - Anfängerkurs'),
			$this->formular(23, 'VORLAGE Anmeldung - Anfängerkurs'),
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
			$this->formular(18, 'Warteliste — Anfängerkurs 12./13.09.2026'),
		];
	}

	/**
	 * Die aeltere Schreibweise mit Gedankenstrich muss weiter erkannt
	 * werden: Bestehende Vorlagen sollen sich nicht umbenennen muessen.
	 *
	 * Zwei Tests, weil die Pruefung ein ODER ist - mit nur einem liesse sich
	 * die eine Haelfte entfernen, ohne dass etwas rot wird.
	 */
	public function testEineAnmeldevorlageMitGedankenstrichZaehltMit(): void {
		$auswahl = Vorlagenauswahl::ausFormularen(
			[$this->formular(23, 'VORLAGE Anmeldung — Anfängerkurs')],
			Formularart::Anmeldung,
		);

		$this->assertSame([23], array_column($auswahl->optionen(), 'id'));
	}

	public function testEineWartelistenvorlageMitGedankenstrichZaehltMit(): void {
		$auswahl = Vorlagenauswahl::ausFormularen(
			[$this->formular(24, 'VORLAGE Warteliste — Anfängerkurs')],
			Formularart::Warteliste,
		);

		$this->assertSame([24], array_column($auswahl->optionen(), 'id'));
	}

	/**
	 * Eine liegengebliebene Kopie darf nicht zur Wahl stehen.
	 *
	 * Scheitert ein Lauf und misslingt auch das Aufraeumen - genau der Fall,
	 * fuer den es Zurueckrollen::hinweis gibt -, bleibt ein Klon
	 * "VORLAGE … - Kopie" in Nextcloud stehen. Titel::zerlege stuft ihn als
	 * Vorlage ein, die Uebersicht zeigt ihn also nirgends. Ohne diese
	 * Pruefung stuende er hier trotzdem zur Wahl: Aus der vorgewaehlten
	 * einzigen Vorlage wuerde "— bitte waehlen —" mit zwei fast gleich
	 * aussehenden Zeilen, und der naechste Kurs entstuende aus einem
	 * halbfertigen Klon.
	 */
	public function testEineLiegengebliebeneKopieStehtNichtZurWahl(): void {
		$mitLeiche = [
			$this->formular(24, 'VORLAGE Warteliste - Anfängerkurs'),
			$this->formular(23, 'VORLAGE Anmeldung - Anfängerkurs'),
			$this->formular(99, 'VORLAGE Warteliste - Anfängerkurs - Kopie'),
		];

		$warteliste = Vorlagenauswahl::ausFormularen($mitLeiche, Formularart::Warteliste);

		$this->assertCount(1, $warteliste->optionen());
		$this->assertFalse($warteliste->freieWahl(),
			'Es bleibt genau eine Vorlage - also keine Auswahl.');
		$this->assertFalse($warteliste->enthaelt(99),
			'Auch der POST darf die Leiche nicht durchlassen.');
	}

	/**
	 * Ein laufender Kurs ist gar nicht klonbar - genau der Fehler, der einem
	 * Formular den Termin des Vorgaengerkurses eingetragen hat.
	 */
	public function testEinLaufenderKursStehtInKeinemFeld(): void {
		$anmeldung = Vorlagenauswahl::ausFormularen($this->bestand(), Formularart::Anmeldung);

		$this->assertFalse($anmeldung->enthaelt(19));
	}

	public function testJedesFeldZeigtNurSeineArt(): void {
		$anmeldung = Vorlagenauswahl::ausFormularen($this->bestand(), Formularart::Anmeldung);
		$warteliste = Vorlagenauswahl::ausFormularen($this->bestand(), Formularart::Warteliste);

		$this->assertTrue($anmeldung->enthaelt(23));
		$this->assertFalse($anmeldung->enthaelt(24));
		$this->assertTrue($warteliste->enthaelt(24));
		$this->assertFalse($warteliste->enthaelt(23));
	}

	/**
	 * Steht genau eine zur Wahl, ist sie gesetzt und "bitte waehlen"
	 * entfaellt. Sicher ist das erst, seit jedes Feld nur seine Art zeigt.
	 */
	public function testEineEinzigeVorlageStehtGleichImFeld(): void {
		$auswahl = Vorlagenauswahl::ausFormularen($this->bestand(), Formularart::Anmeldung);

		$this->assertFalse($auswahl->freieWahl());
		$this->assertTrue($auswahl->optionen()[0]['gewaehlt']);
	}

	public function testBeiZweiVorlagenIstNichtsVorbelegt(): void {
		$bestand = $this->bestand();
		$bestand[] = $this->formular(25, 'VORLAGE Anmeldung - Fortgeschrittene');

		$auswahl = Vorlagenauswahl::ausFormularen($bestand, Formularart::Anmeldung);

		$this->assertTrue($auswahl->freieWahl());
		foreach ($auswahl->optionen() as $option) {
			$this->assertFalse($option['gewaehlt']);
		}
	}

	/**
	 * Der Trenner gehoert zum Praefix. Ohne ihn zaehlte "VORLAGE
	 * Anmeldungsbogen" als Anmeldevorlage.
	 */
	public function testDerTrennerGehoertZumPraefix(): void {
		$bestand = [$this->formular(30, 'VORLAGE Anmeldungsbogen')];

		$auswahl = Vorlagenauswahl::ausFormularen($bestand, Formularart::Anmeldung);

		$this->assertTrue($auswahl->istLeer());
	}

	/**
	 * Eine Vorlage, deren Titel die Art nicht nennt, steht in KEINEM der
	 * beiden Felder. Wer sie benutzen will, benennt sie um, und das faellt
	 * sofort auf.
	 */
	public function testEineVorlageOhneArtStehtNirgends(): void {
		$bestand = [$this->formular(31, 'VORLAGE Irgendwas')];

		$this->assertTrue(Vorlagenauswahl::ausFormularen(
			$bestand, Formularart::Anmeldung)->istLeer());
		$this->assertTrue(Vorlagenauswahl::ausFormularen(
			$bestand, Formularart::Warteliste)->istLeer());
	}

	/**
	 * Die Praefix-Pruefung in ausFormularen steht getrennt von der Art, und
	 * NUR dieser Fall haelt sie fest: Ohne sie kaeme bei Unbekannt jeder
	 * laufende Kurs zurueck - alles, was weder Anmelde- noch
	 * Wartelistenvorlage ist.
	 *
	 * Entfernt man die Zeile, bleiben alle anderen Tests gruen - dieser
	 * hier ist der einzige, der sie haelt.
	 */
	public function testUnbekannteArtLiefertKeineLaufendenKurse(): void {
		$auswahl = Vorlagenauswahl::ausFormularen($this->bestand(), Formularart::Unbekannt);

		$this->assertFalse($auswahl->enthaelt(19));
		$this->assertFalse($auswahl->enthaelt(18));
	}

	public function testDasPaarNenntNurDieFehlendeArt(): void {
		$paar = Vorlagenpaar::ausFormularen([
			$this->formular(23, 'VORLAGE Anmeldung - Anfängerkurs'),
		]);

		$fehlende = $paar->fehlendeArten();

		$this->assertCount(1, $fehlende);
		$this->assertStringContainsString('Warteliste', $fehlende[0]);
	}

	public function testEinVollstaendigesPaarVermisstNichts(): void {
		$paar = Vorlagenpaar::ausFormularen($this->bestand());

		$this->assertSame([], $paar->fehlendeArten());
	}
}
