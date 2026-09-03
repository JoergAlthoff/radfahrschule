<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Formulare;

use OCA\Radfahrschule\Formulare\Formular;
use PHPUnit\Framework\TestCase;

class FormularTest extends TestCase {
	public function testAusDerAntwortVonForms(): void {
		// Erfundene Werte. KEINE gemessenen: Ein Share-Hash ist der
		// oeffentliche Formularlink - wer ihn hat, kann abgeben.
		$formular = Formular::ausAntwort([
			'id' => 19,
			'hash' => 'abcdefghijklmnop',
			'title' => 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			'description' => 'Bitte anmelden.',
			'submissionCount' => 6,
			'expires' => 1789200000,
		]);

		$this->assertSame(19, $formular->id);
		$this->assertSame('abcdefghijklmnop', $formular->hash);
		$this->assertSame(6, $formular->abgaben);
	}

	// Die Liste liefert weniger als das einzelne Formular: description und
	// shares fehlen dort. Fehlende Felder duerfen nicht abstuerzen.
	public function testFehlendeFelderSindLeer(): void {
		$formular = Formular::ausAntwort([
			'id' => 20,
			'hash' => 'ponmlkjihgfedcba',
			'title' => 'Warteliste — Anfängerkurs 12./13.09.2026',
			'submissionCount' => 0,
		]);

		$this->assertSame('', $formular->beschreibung);
		$this->assertSame(0, $formular->ablauf);
	}

	public function testFrageMitNamenFindetUeberDenTechnischenSchluessel(): void {
		$formular = Formular::ausAntwort([
			'id' => 7,
			'title' => 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			'questions' => [
				['id' => 30, 'name' => 'name', 'text' => 'Dein Name', 'description' => ''],
				['id' => 31, 'name' => 'teilnahmebedingungen', 'text' => 'Bedingungen',
					'description' => "- **Termin:** 12./13.09.2026"],
			],
		]);

		$frage = $formular->frageMitNamen('teilnahmebedingungen');

		$this->assertNotNull($frage);
		$this->assertSame(31, $frage->id);
		$this->assertSame("- **Termin:** 12./13.09.2026", $frage->beschreibung);
	}

	public function testFrageMitUnbekanntemNamenIstNull(): void {
		$formular = Formular::ausAntwort(['id' => 7, 'questions' => []]);

		$this->assertNull($formular->frageMitNamen('teilnahmebedingungen'));
	}

	/**
	 * Der oeffentliche Hash kommt aus den Freigaben, NICHT aus dem Feld
	 * "hash". Letzteres ist die Editor-Adresse mit 16 Zeichen; als
	 * oeffentlicher Link fuehrt er ins Leere.
	 *
	 * Die Laenge der beiden Werte ist hier funktionaler Teil des Tests.
	 * Beide sind erfunden - ein echter Share-Hash ist der Anmeldelink eines
	 * Formulars und gehoert nicht ins Repository.
	 */
	public function testDerOeffentlicheHashKommtAusDenFreigaben(): void {
		$formular = Formular::ausAntwort([
			'id' => 7,
			'hash' => 'aaaaaaaaaaaaaaaa',
			'shares' => [
				['id' => 1, 'shareType' => 1, 'shareWith' => 'Radfahrschule'],
				['id' => 2, 'shareType' => 3, 'shareWith' => 'bbbbbbbbbbbbbbbbbbbbbbbb'],
			],
		]);

		$this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbb', $formular->oeffentlicherHash());
	}

	public function testOhneLinkFreigabeKeinOeffentlicherHash(): void {
		$formular = Formular::ausAntwort([
			'id' => 7,
			'hash' => 'aaaaaaaaaaaaaaaa',
			'shares' => [['id' => 1, 'shareType' => 1, 'shareWith' => 'Radfahrschule']],
		]);

		$this->assertNull($formular->oeffentlicherHash());
	}
}
