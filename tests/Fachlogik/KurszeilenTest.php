<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

final class KurszeilenTest extends TestCase {
	use MitTitelmuster;

	private function formular(int $id, string $titel, int $abgaben = 0): Formular {
		return new Formular(
			id: $id, hash: 'editor' . str_pad((string)$id, 10, '0', STR_PAD_LEFT),
			titel: $titel, beschreibung: '', abgaben: $abgaben, ablauf: 0,
		);
	}

	/** @param list<Formular> $formulare */
	private function einzigerKurs(array $formulare): Kurs {
		$kurse = Kursliste::ausFormularen($formulare, $this->titelmuster());
		$this->assertCount(1, $kurse);
		return $kurse[0];
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	/**
	 * Die Anmeldung steht vorn, und das ist zugleich die LOESCHREIHENFOLGE.
	 * Bricht es danach ab, bleibt eine Warteliste ohne Anmeldung - unschoen,
	 * aber harmlos. Andersherum stuende eine Anmeldung da, deren
	 * Wartelisten-Link ins Leere fuehrt.
	 */
	public function testDieAnmeldungStehtVornInDenZeilen(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(18, 'Warteliste — Anfängerkurs 12./13.09.2026'),
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
		]);

		$zeilen = $kurs->zeilen();

		$this->assertCount(2, $zeilen);
		$this->assertSame('Anmeldung', $zeilen[0]->beschriftung);
		$this->assertSame(19, $zeilen[0]->formular->id);
		$this->assertSame('Warteliste', $zeilen[1]->beschriftung);
	}

	/**
	 * Ein zweites Formular derselben Art wird NICHT verworfen, sondern
	 * hinten angehaengt. Die Zeilen sind die Loeschliste: Was hier fehlt,
	 * bleibt in Nextcloud zurueck, waehrend das Protokoll Erfolg meldet.
	 */
	public function testEinDoppeltAngelegtesFormularGehtNichtVerloren(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
			$this->formular(20, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
			$this->formular(18, 'Warteliste — Anfängerkurs 12./13.09.2026'),
		]);

		$zeilen = $kurs->zeilen();

		$this->assertCount(3, $zeilen);
		$this->assertTrue($kurs->istDoppelt());
		// Die doppelten zuletzt - sie sind der Sonderfall und sollen die
		// gewohnte Reihenfolge nicht durcheinanderbringen.
		$this->assertSame('Doppelt angelegt', $zeilen[2]->beschriftung);
		$this->assertSame(20, $zeilen[2]->formular->id);
	}

	public function testEinGewoehnlicherKursIstNichtDoppelt(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
			$this->formular(18, 'Warteliste — Anfängerkurs 12./13.09.2026'),
		]);

		$this->assertFalse($kurs->istDoppelt());
	}

	/**
	 * Ein Formular ohne erkennbare Art bildet einen eigenen Eintrag und
	 * gehoert trotzdem in die Zeilen - sonst bliebe es beim Loeschen stehen.
	 */
	public function testEinFormularOhneArtStehtAlsSonstiges(): void {
		$kurs = $this->einzigerKurs([$this->formular(30, 'Irgendwas ohne Präfix')]);

		$zeilen = $kurs->zeilen();

		$this->assertCount(1, $zeilen);
		$this->assertSame('Ohne Art', $zeilen[0]->beschriftung);
	}

	/**
	 * Wird eine Haelfte in Nextcloud umbenannt, faellt sie aus dem Paar. Was
	 * bleibt, sieht aus wie ein vollstaendiger Kurs - und wer ihn loescht,
	 * bekaeme "samt Anmeldedaten entfernt" zu lesen, waehrend die andere
	 * Haelfte mit ihren Eintraegen stehenbleibt.
	 */
	public function testEinHalberKursNenntDieFehlendeHaelfte(): void {
		$nurAnmeldung = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
		]);
		$this->assertSame('Warteliste', $nurAnmeldung->fehlendeHaelfte());

		$nurWarteliste = $this->einzigerKurs([
			$this->formular(18, 'Warteliste — Anfängerkurs 12./13.09.2026'),
		]);
		$this->assertSame('Anmeldung', $nurWarteliste->fehlendeHaelfte());
	}

	public function testEinVollstaendigerKursVermisstNichts(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
			$this->formular(18, 'Warteliste — Anfängerkurs 12./13.09.2026'),
		]);

		$this->assertNull($kurs->fehlendeHaelfte());
	}

	/**
	 * Ein Formular ohne Art im selben Kurs ist keine fehlende Haelfte - es
	 * ist der wahrscheinlichste Kandidat FUER die umbenannte Haelfte.
	 *
	 * Nimmt jemand der Warteliste in Nextcloud das Praefix, faellt sie nicht
	 * aus dem Kurs: Der Rest des Titels ist die Kennung, sie landet als
	 * "sonstiges" im selben Kurs. zeilen() - die Loeschliste - fasst sie an,
	 * fehlendeHaelfte() zaehlte sie aber nicht mit. Die Kursseite schrieb
	 * "Beim Loeschen bleibt sie stehen" ueber ein Formular, das sie eine
	 * Sekunde spaeter samt Anmeldedaten loescht.
	 */
	public function testEinFormularOhneArtIstKeineFehlendeHaelfte(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 12),
			$this->formular(18, 'Anfängerkurs 12./13.09.2026', 7),
		]);

		$zeilen = $kurs->zeilen();
		$this->assertCount(2, $zeilen, 'Beide Formulare gehoeren in denselben Kurs.');
		$this->assertSame('Ohne Art', $zeilen[1]->beschriftung);

		// Es wird mitgeloescht - also darf die Seite nichts anderes sagen.
		$this->assertNull($kurs->fehlendeHaelfte());
	}

	/**
	 * Auch der Fall, dass beide fehlen: Ein Eintrag aus einem Formular ohne
	 * erkennbare Art ist kein halber Kurs.
	 */
	public function testEinFormularOhneArtIstKeinHalberKurs(): void {
		$kurs = $this->einzigerKurs([$this->formular(30, 'Irgendwas ohne Präfix')]);

		$this->assertNull($kurs->fehlendeHaelfte());
	}

	/**
	 * Die Zeitform kommt aus der Uhr und darf nicht in der Vorlage stehen.
	 * Dort waere sie fest, und ein kommender Kurs laese sich, als sei er
	 * vorbei - beim Loeschen die gefaehrlichste Verwechslung, die es hier
	 * gibt.
	 */
	public function testDerKurstagsatzTraegtDieRichtigeZeitform(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
		]);

		$this->assertStringContainsString('ist am 13.09.2026', $kurs->kurstagSatz($this->jetzt()));

		$spaeter = new DateTimeImmutable('2026-10-01', new DateTimeZone('UTC'));
		$this->assertStringContainsString('war am 13.09.2026', $kurs->kurstagSatz($spaeter));
	}

	/**
	 * Der Kurstag selbst zaehlt zur Gegenwart. Die Uhrzeit ist hier
	 * funktionaler Teil des Tests - verglichen wird auf Tagesebene, sonst
	 * waere der Kurs ab 00:01 gewesen, waehrend er noch laeuft.
	 */
	public function testAmKurstagSelbstIstDerKursGegenwart(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
		]);

		$amKurstag = new DateTimeImmutable('2026-09-13 18:30:00', new DateTimeZone('UTC'));

		$this->assertStringContainsString('ist am', $kurs->kurstagSatz($amKurstag));
	}

	public function testOhneDatumGibtEsKeinenKurstagsatz(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(19, 'Radfahrschule Musterstadt — Sommerfest'),
		]);

		$this->assertSame('', $kurs->kurstagSatz($this->jetzt()));
	}

	/** Die id, unter der die Detailseite diesen Kurs findet. */
	public function testEineIdIstDieDesErstenFormulars(): void {
		$kurs = $this->einzigerKurs([
			$this->formular(18, 'Warteliste — Anfängerkurs 12./13.09.2026'),
			$this->formular(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026'),
		]);

		$this->assertSame(19, $kurs->eineId());
	}
}
