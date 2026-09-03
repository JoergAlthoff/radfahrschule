<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Kurstag;
use PHPUnit\Framework\TestCase;

class ReihenfolgeTest extends TestCase {
	private function kurs(string $kennung): Kurs {
		return new Kurs(
			kennung: $kennung,
			letzterTag: Kurstag::letzterAus($kennung),
		);
	}

	/**
	 * @param list<Kurs> $kurse
	 * @return list<string>
	 */
	private function kennungen(array $kurse): array {
		return array_map(static fn (Kurs $kurs): string => $kurs->kennung, $kurse);
	}

	// "Naechster" hat nur eine Lesart. "Neuester" hatte zwei - und der
	// Code las sie als "spaetestes Datum oben", worauf der naechste Kurs
	// ganz unten stand und einer versehentlich geloescht wurde.
	public function testNaechsterKursStehtOben(): void {
		$jetzt = new DateTimeImmutable('2026-08-25 14:00:00', new DateTimeZone('UTC'));

		$sortiert = Kursliste::naechsterZuerst([
			$this->kurs('Anfängerkurs 20./21.12.2026'),
			$this->kurs('Anfängerkurs 12./13.09.2026'),
			$this->kurs('Anfängerkurs 07./08.03.2026'),
		], $jetzt);

		$this->assertSame([
			'Anfängerkurs 12./13.09.2026',  // kommt als naechstes
			'Anfängerkurs 20./21.12.2026',  // kommt danach
			'Anfängerkurs 07./08.03.2026',  // war schon
		], $this->kennungen($sortiert));
	}

	/**
	 * Unter den vergangenen Kursen steht der juengste oben - man sucht den,
	 * der eben gelaufen ist, nicht den von vorletztem Jahr.
	 *
	 * Der Test oben hat nur EINEN vergangenen Kurs; damit werden nie zwei
	 * vergangene miteinander verglichen, und der Zweig bliebe ungeprueft.
	 */
	public function testVergangeneStehenAbsteigend(): void {
		$jetzt = new DateTimeImmutable('2026-08-25 14:00:00', new DateTimeZone('UTC'));

		$sortiert = Kursliste::naechsterZuerst([
			$this->kurs('Anfängerkurs 07./08.03.2026'),
			$this->kurs('Anfängerkurs 10./11.07.2026'),
			$this->kurs('Anfängerkurs 20./21.05.2026'),
		], $jetzt);

		$this->assertSame([
			'Anfängerkurs 10./11.07.2026',  // zuletzt gelaufen
			'Anfängerkurs 20./21.05.2026',
			'Anfängerkurs 07./08.03.2026',  // am laengsten her
		], $this->kennungen($sortiert));
	}

	public function testOhneDatumGanzNachUnten(): void {
		$jetzt = new DateTimeImmutable('2026-08-25 14:00:00', new DateTimeZone('UTC'));

		$sortiert = Kursliste::naechsterZuerst([
			$this->kurs('Sonderkurs ohne Datum'),
			$this->kurs('Anfängerkurs 12./13.09.2026'),
		], $jetzt);

		$this->assertSame([
			'Anfängerkurs 12./13.09.2026',
			'Sonderkurs ohne Datum',
		], $this->kennungen($sortiert));
	}

	/**
	 * Zwei Kurse ohne Datum sind untereinander gleichrangig und behalten
	 * deshalb ihre Eingabereihenfolge - PHP sortiert seit 8.0 stabil.
	 *
	 * Der Test oben deckt das nicht ab: Bei nur EINEM Kurs ohne Datum wird
	 * der Zweig fuer "beide ohne" nie durchlaufen.
	 */
	public function testZweiOhneDatumBehaltenIhreReihenfolge(): void {
		$jetzt = new DateTimeImmutable('2026-08-25 14:00:00', new DateTimeZone('UTC'));

		$sortiert = Kursliste::naechsterZuerst([
			$this->kurs('Sonderkurs Zwei ohne Datum'),
			$this->kurs('Anfängerkurs 12./13.09.2026'),
			$this->kurs('Sonderkurs Eins ohne Datum'),
		], $jetzt);

		$this->assertSame([
			'Anfängerkurs 12./13.09.2026',
			'Sonderkurs Zwei ohne Datum',    // stand vorne und bleibt vorne
			'Sonderkurs Eins ohne Datum',
		], $this->kennungen($sortiert));
	}

	// Ein Kurs, der heute stattfindet, gilt als kommend - sonst rutschte
	// er um 00:01 nach unten, waehrend er noch laeuft. Verglichen wird
	// deshalb auf Tagesebene, nicht auf Zeitpunkte.
	//
	// Der dritte Kurs in der Zukunft ist kein Beiwerk, und die volle Liste
	// statt nur des ersten Eintrags auch nicht. Mit zwei Kursen gaelten bei
	// einer verschobenen Grenze BEIDE als vergangen; vergangene stehen
	// absteigend, also stuende derselbe Kurs wieder oben und die Pruefung
	// ginge durch, obwohl aus >= ein > geworden ist.
	public function testHeuteGiltAlsKommend(): void {
		$jetzt = new DateTimeImmutable('2026-09-13 23:30:00', new DateTimeZone('UTC'));

		$kennungen = [
			'Anfängerkurs 07./08.03.2026',   // war schon
			'Anfängerkurs 20./21.12.2026',   // kommt spaeter
			'Anfängerkurs 12./13.09.2026',   // endet heute
		];
		$erwartet = [
			'Anfängerkurs 12./13.09.2026',   // heute - und damit der naechste
			'Anfängerkurs 20./21.12.2026',
			'Anfängerkurs 07./08.03.2026',
		];

		// Jede Eingabereihenfolge muss dasselbe ergeben, und alle sechs
		// werden gebraucht. Die Grenze steht zweimal im Code, einmal je
		// Vergleichspartner; verschiebt man nur die zweite, widerspricht
		// sich der Vergleich - derselbe Kurs gilt mal als kommend, mal
		// nicht, je nach Position. Nur DREI der sechs Reihenfolgen zeigen
		// das; zwei beliebige herauszugreifen genuegt nicht.
		$reihenfolgen = [[0, 1, 2], [0, 2, 1], [1, 0, 2], [1, 2, 0], [2, 0, 1], [2, 1, 0]];

		foreach ($reihenfolgen as $reihenfolge) {
			$eingabe = [];
			foreach ($reihenfolge as $platz) {
				$eingabe[] = $this->kurs($kennungen[$platz]);
			}

			$sortiert = Kursliste::naechsterZuerst($eingabe, $jetzt);

			$this->assertSame(
				$erwartet,
				$this->kennungen($sortiert),
				'Eingabereihenfolge ' . implode(',', $reihenfolge),
			);
		}
	}
}
