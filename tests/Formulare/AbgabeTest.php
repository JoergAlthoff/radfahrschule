<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Formulare;

use OCA\Radfahrschule\Formulare\Abgabe;
use PHPUnit\Framework\TestCase;

final class AbgabeTest extends TestCase {
	/** @return array<string, mixed> eine Abgabe, wie Forms sie liefert */
	private function antwortVonForms(): array {
		return [
			'id' => 127,
			'formId' => 967,
			'userId' => 'radfahrschule',
			'timestamp' => 1791629820,
			'answers' => [
				['questionId' => 9256, 'text' => 'Frau', 'questionName' => 'anrede'],
				['questionId' => 9257, 'text' => ' Erika ', 'questionName' => 'vorname'],
				['questionId' => 9258, 'text' => 'Muster', 'questionName' => 'nachname'],
				['questionId' => 9263, 'text' => 'Erste Wahl', 'questionName' => 'vorerfahrungen'],
				['questionId' => 9263, 'text' => 'Zweite Wahl', 'questionName' => 'vorerfahrungen'],
			],
		];
	}

	public function testNummerUndZeitpunktKommenAusDerAntwort(): void {
		$abgabe = Abgabe::ausAntwort($this->antwortVonForms());

		$this->assertSame(127, $abgabe->id);
		$this->assertSame(1791629820, $abgabe->zeitpunkt);
	}

	/** Eine Frage mit zwei angekreuzten Kaestchen liefert zwei Antworten. */
	public function testAlleAntwortenEinerFrageBleibenErhalten(): void {
		$abgabe = Abgabe::ausAntwort($this->antwortVonForms());

		$this->assertSame(['Frau'], $abgabe->antworten['anrede']);
		$this->assertSame(['Erste Wahl', 'Zweite Wahl'], $abgabe->antworten['vorerfahrungen']);
	}

	public function testDerNameIstVorUndNachname(): void {
		$abgabe = Abgabe::ausAntwort($this->antwortVonForms());

		$this->assertSame('Erika Muster', $abgabe->name());
	}

	public function testOhneNamenBleibtDerNameLeer(): void {
		$abgabe = Abgabe::ausAntwort(['id' => 1, 'timestamp' => 5, 'answers' => []]);

		$this->assertSame('', $abgabe->name());
	}

	public function testEineAntwortOhneNamenDerFrageWirdUebersprungen(): void {
		$abgabe = Abgabe::ausAntwort(['id' => 1, 'timestamp' => 5, 'answers' => [
			['questionId' => 1, 'text' => 'ohne Namen'],
			['questionId' => 2, 'text' => 'Erika', 'questionName' => 'vorname'],
		]]);

		$this->assertSame(['vorname' => ['Erika']], $abgabe->antworten);
		$this->assertSame(1, $abgabe->antwortenOhneFragenname);
	}

	public function testOhneUebersprungeneAntwortenStehtDieZahlAufNull(): void {
		$abgabe = Abgabe::ausAntwort($this->antwortVonForms());

		$this->assertSame(0, $abgabe->antwortenOhneFragenname);
	}

	public function testNurDerVornameGibtEinenNamenOhneLeerzeichenAmRand(): void {
		$abgabe = Abgabe::ausAntwort(['id' => 1, 'timestamp' => 5, 'answers' => [
			['questionId' => 2, 'text' => 'Erika', 'questionName' => 'vorname'],
		]]);

		$this->assertSame('Erika', $abgabe->name());
	}

	public function testNurDerNachnameGibtEinenNamenOhneLeerzeichenAmRand(): void {
		$abgabe = Abgabe::ausAntwort(['id' => 1, 'timestamp' => 5, 'answers' => [
			['questionId' => 3, 'text' => 'Muster', 'questionName' => 'nachname'],
		]]);

		$this->assertSame('Muster', $abgabe->name());
	}
}
