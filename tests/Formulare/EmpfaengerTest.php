<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Formulare;

use OCA\Radfahrschule\Formulare\Empfaenger;
use PHPUnit\Framework\TestCase;

final class EmpfaengerTest extends TestCase {
	/**
	 * Eine Abgabe, wie Forms sie liefert: jede Antwort mit ihrem
	 * technischen Namen, Auswahlfragen als Text.
	 *
	 * @return array<string, mixed>
	 */
	private function abgabe(): array {
		return [
			'id' => 1,
			'answers' => [
				['questionId' => 4306, 'text' => 'Frau', 'questionName' => 'anrede'],
				['questionId' => 4307, 'text' => 'Erika', 'questionName' => 'vorname'],
				['questionId' => 4308, 'text' => 'Muster', 'questionName' => 'nachname'],
				['questionId' => 4309, 'text' => '40', 'questionName' => 'alter'],
				['questionId' => 4311, 'text' => 'erika.muster@example.org', 'questionName' => 'email'],
				['questionId' => 4314, 'text' => 'Ich komme mit dem Bus', 'questionName' => 'kommentar'],
			],
		];
	}

	public function testDieVierFelderWerdenUeberDenNamenGefunden(): void {
		$empfaenger = Empfaenger::ausAbgabe($this->abgabe());

		$this->assertSame('Frau', $empfaenger->anrede);
		$this->assertSame('Erika', $empfaenger->vorname);
		$this->assertSame('Muster', $empfaenger->nachname);
		$this->assertSame('erika.muster@example.org', $empfaenger->mailadresse);
	}

	/**
	 * Alter und Kommentar stehen in der Abgabe. Sie duerfen das Objekt nicht
	 * erreichen - was hier nicht steht, verlaesst die Formulare nicht. Kommt
	 * spaeter ein Feld dazu, wird dieser Test rot, und das ist Absicht.
	 */
	public function testMehrAlsVierFelderGibtEsNicht(): void {
		$empfaenger = Empfaenger::ausAbgabe($this->abgabe());

		$this->assertSame(
			['anrede', 'vorname', 'nachname', 'mailadresse'],
			array_keys(get_object_vars($empfaenger)));
	}

	/** Die Anrede ist im Formular kein Pflichtfeld. */
	public function testOhneAnredeBleibtSieLeer(): void {
		$abgabe = $this->abgabe();
		array_shift($abgabe['answers']);

		$this->assertSame('', Empfaenger::ausAbgabe($abgabe)->anrede);
	}

	/**
	 * Die Reihenfolge der Antworten ist nicht zugesagt. Wer ueber die
	 * Position liest, liest bei einer umgestellten Vorlage den Vornamen als
	 * Mailadresse.
	 */
	public function testDieReihenfolgeDerAntwortenSpieltKeineRolle(): void {
		$abgabe = $this->abgabe();
		$abgabe['answers'] = array_reverse($abgabe['answers']);

		$this->assertSame('erika.muster@example.org',
			Empfaenger::ausAbgabe($abgabe)->mailadresse);
	}

	/** Ein abgetipptes Feld traegt leicht ein Leerzeichen am Rand. */
	public function testRandleerzeichenFallenWeg(): void {
		$abgabe = ['answers' => [
			['text' => " erika.muster@example.org\n", 'questionName' => 'email'],
		]];

		$this->assertSame('erika.muster@example.org',
			Empfaenger::ausAbgabe($abgabe)->mailadresse);
	}

	public function testDerNameStehtZusammen(): void {
		$this->assertSame('Erika Muster', Empfaenger::ausAbgabe($this->abgabe())->name());
	}

	/** Ohne Vorname kein fuehrendes Leerzeichen. */
	public function testEinFehlenderVornameHinterlaesstKeinLeerzeichen(): void {
		$empfaenger = new Empfaenger('', '', 'Muster', 'x@example.org');

		$this->assertSame('Muster', $empfaenger->name());
	}
}
