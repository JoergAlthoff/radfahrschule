<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use OCA\Radfahrschule\Fachlogik\Kurstag;
use PHPUnit\Framework\TestCase;

class KurstagTest extends TestCase {
	public function testZweitagesKursLiefertDenZweitenTag(): void {
		$tag = Kurstag::letzterAus('Anfängerkurs 12./13.09.2026');

		$this->assertNotNull($tag);
		$this->assertSame('2026-09-13', $tag->format('Y-m-d'));
	}

	public function testEintagesKursLiefertSeinenTag(): void {
		$tag = Kurstag::letzterAus('Auffrischung 05.07.2026');

		$this->assertNotNull($tag);
		$this->assertSame('2026-07-05', $tag->format('Y-m-d'));
	}

	public function testKennungOhneDatumLiefertNichts(): void {
		$this->assertNull(Kurstag::letzterAus('Sonderkurs'));
	}

	/**
	 * Ein Kalendertag, den es nicht gibt, muss null geben - nicht den
	 * naechsten.
	 *
	 * createFromFormat rollt still weiter: Aus dem 31.09. wird der 01.10.
	 * Die Uebersicht sortiert und fristet danach, die Kursseite schrieb
	 * "Der Kurs ist am 01.10.2026." neben eine Ueberschrift mit dem 31.09.,
	 * und das Verschiebeformular war mit dem falschen Tag vorbelegt. Der
	 * null-Zweig, fuer den die Methode ihren Rueckgabetyp ?DateTimeImmutable
	 * traegt, war nur bei voellig fehlendem Datum erreichbar.
	 */
	public function testEinUnmoeglicherKalendertagLiefertNichts(): void {
		$this->assertNull(Kurstag::letzterAus('Anfängerkurs 31.09.2026'));
		$this->assertNull(Kurstag::letzterAus('Anfängerkurs 30.02.2026'));
	}

	/**
	 * Auch der VORSATZ darf nicht weiterrollen. Er wird getrennt gebaut, hat
	 * also seinen eigenen Weg in dieselbe Falle.
	 */
	public function testEinUnmoeglicherErsterKurstagLiefertNichts(): void {
		$this->assertNull(Kurstag::ersterAus('Anfängerkurs 31.09./01.10.2026'));
	}

	// Der Tag steht immer in UTC, nie in der Ortszeit. Sonst ist
	// Mitternacht MEZ gleich 23:00 UTC des Vortags, und die Differenz
	// zweier Tage waere 23 Stunden statt 24 - abgerundet null Tage.
	// Im Container faellt das nie auf, der laeuft in UTC.
	//
	// Wer das nachstellen will, braucht eine andere Vorgabe-Zeitzone -
	// steht die Umgebung selbst auf UTC, bleibt der Test auch ohne die
	// Zone gruen:
	// php -d date.timezone=Europe/Berlin vendor/bin/phpunit --filter KurstagTest
	/**
	 * Der erste Kurstag steht im Vorsatz vor dem Schraegstrich - und je nach
	 * Form fehlen ihm Monat und Jahr. Termin::kurz kennt drei Schreibweisen,
	 * und alle drei muessen zurueckgelesen werden koennen.
	 */
	public function testDerErsteTagKommtAusDemVorsatz(): void {
		$erster = Kurstag::ersterAus('Anfängerkurs 12./13.09.2026');

		$this->assertNotNull($erster);
		$this->assertSame('12.09.2026', $erster->format('d.m.Y'));
	}

	/** Ueber einen Monatswechsel traegt der Vorsatz seinen eigenen Monat. */
	public function testUeberDenMonatswechselTraegtDerVorsatzDenMonat(): void {
		$erster = Kurstag::ersterAus('Anfängerkurs 30.09./01.10.2026');

		$this->assertNotNull($erster);
		$this->assertSame('30.09.2026', $erster->format('d.m.Y'));
	}

	/** Ohne Schraegstrich ist der Kurs eintaegig: erster Tag = letzter Tag. */
	public function testOhneSchraegstrichSindBeideTageGleich(): void {
		$erster = Kurstag::ersterAus('Anfängerkurs 13.09.2026');

		$this->assertNotNull($erster);
		$this->assertSame('13.09.2026', $erster->format('d.m.Y'));
	}

	public function testOhneDatumGibtEsKeinenErstenTag(): void {
		$this->assertNull(Kurstag::ersterAus('Anfängerkurs ohne Datum'));
	}

	/** Dieselbe Zone wie der letzte Tag - sonst waeren sie nicht vergleichbar. */
	public function testAuchDerErsteTagStehtInUtc(): void {
		$erster = Kurstag::ersterAus('Anfängerkurs 12./13.09.2026');

		$this->assertNotNull($erster);
		$this->assertSame('UTC', $erster->getTimezone()->getName());
		$this->assertSame('00:00:00', $erster->format('H:i:s'));
	}

	public function testDerTagStehtInUtc(): void {
		$tag = Kurstag::letzterAus('Anfängerkurs 12./13.09.2026');

		$this->assertNotNull($tag);
		$this->assertSame('UTC', $tag->getTimezone()->getName());
		$this->assertSame('00:00:00', $tag->format('H:i:s'));
	}
}
