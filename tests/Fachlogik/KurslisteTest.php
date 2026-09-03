<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

class KurslisteTest extends TestCase {
	use MitTitelmuster;

	/** @return list<Formular> */
	private function beispielformulare(): array {
		return [
			new Formular(18, 'hash18', 'Warteliste — Anfängerkurs 12./13.09.2026', '', 2, 0),
			new Formular(19, 'hash19', 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', '', 6, 0),
			new Formular(23, 'hash23', 'VORLAGE Anmeldung - Anfängerkurs', '', 0, 0),
		];
	}

	public function testZweiFormulareWerdenEinKurs(): void {
		$kurse = Kursliste::ausFormularen($this->beispielformulare(), $this->titelmuster());

		$this->assertCount(1, $kurse, 'die Vorlage gehoert in keinen Kurs');
		$this->assertSame('Anfängerkurs 12./13.09.2026', $kurse[0]->kennung);
		$this->assertSame(19, $kurse[0]->anmeldung?->id);
		$this->assertSame(18, $kurse[0]->warteliste?->id);
	}

	public function testVorlagenBleibenDraussen(): void {
		$kurse = Kursliste::ausFormularen([
			new Formular(23, 'hash23', 'VORLAGE Anmeldung - Anfängerkurs', '', 0, 0),
			new Formular(24, 'hash24', 'VORLAGE Warteliste - Anfängerkurs', '', 0, 0),
		], $this->titelmuster());

		$this->assertSame([], $kurse);
	}

	public function testAnmeldungenUndWartendeWerdenGetrenntGezaehlt(): void {
		$kurse = Kursliste::ausFormularen($this->beispielformulare(), $this->titelmuster());

		$this->assertSame(6, $kurse[0]->anmeldungen());
		$this->assertSame(2, $kurse[0]->wartende());
	}

	public function testDerKurstagKommtAusDerKennung(): void {
		$kurse = Kursliste::ausFormularen($this->beispielformulare(), $this->titelmuster());

		$this->assertNotNull($kurse[0]->letzterTag);
		$this->assertSame('2026-09-13', $kurse[0]->letzterTag->format('Y-m-d'));
	}
}
