<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Protokoll;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Protokoll\Vorgang;
use PHPUnit\Framework\TestCase;

final class VorgangTest extends TestCase {
	private function zeitpunkt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	public function testEinAngelegterKursTraegtAlleAngaben(): void {
		$vorgang = Vorgang::angelegt(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			kennung: 'Anfängerkurs 10./11.10.2026',
			kurstag: '2026-10-11',
			anmeldungId: 42,
			wartelisteId: 41,
			plaetze: 6,
		);

		$this->assertSame('kurs.angelegt', $vorgang->vorgang);
		$this->assertSame('anna', $vorgang->benutzer);
		$this->assertSame('Anfängerkurs 10./11.10.2026', $vorgang->kennung);
		$this->assertSame('2026-10-11', $vorgang->kurstag);
		$this->assertSame(42, $vorgang->anmeldungId);
		$this->assertSame(6, $vorgang->plaetze);
		$this->assertNull($vorgang->grund);
	}

	/**
	 * Dieser Fall verlangt von niemandem etwas: Es ist nichts entstanden.
	 * Er traegt deshalb KEINE Formular-IDs - es gibt nichts mehr, worauf sie
	 * zeigen koennten.
	 */
	public function testEinZurueckgerollterLaufNenntKeineIds(): void {
		$vorgang = Vorgang::zurueckgerollt(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			kennung: 'Anfängerkurs 10./11.10.2026',
			kurstag: '2026-10-11',
			grund: 'Schritt 8 von 13 fehlgeschlagen',
		);

		$this->assertSame('kurs.anlegen_zurueckgerollt', $vorgang->vorgang);
		$this->assertNull($vorgang->anmeldungId);
		$this->assertNull($vorgang->wartelisteId);
		$this->assertNotNull($vorgang->grund);
	}

	/**
	 * Der einzige Fall, der Handarbeit verlangt. Wer spaeter aufraeumt,
	 * findet hier die IDs - unter denselben Feldnamen wie beim Anlegen,
	 * damit aus ihnen EINE Spalte werden kann.
	 */
	public function testEinAbbruchNenntWasStehenblieb(): void {
		$vorgang = Vorgang::anlegenAbgebrochen(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			kennung: 'Anfängerkurs 10./11.10.2026',
			kurstag: '2026-10-11',
			anmeldungId: 42,
			wartelisteId: null,
			grund: 'Das Aufräumen misslang.',
		);

		$this->assertSame('kurs.anlegen_abgebrochen', $vorgang->vorgang);
		$this->assertSame(42, $vorgang->anmeldungId);
		$this->assertNull($vorgang->wartelisteId);
	}

	/**
	 * Der Zeitpunkt steht in UTC. Das Protokoll wird spaeter sortiert und
	 * verglichen; zwei Zeilen in verschiedenen Zonen liessen sich nicht mehr
	 * ordnen.
	 */
	public function testDerZeitpunktStehtInUtc(): void {
		$berlin = new DateTimeImmutable('2026-07-01 14:00:00', new DateTimeZone('Europe/Berlin'));

		$vorgang = Vorgang::angelegt(
			jetzt: $berlin, benutzer: 'anna', kennung: 'K', kurstag: '2026-10-11',
			anmeldungId: 1, wartelisteId: 2, plaetze: 6,
		);

		$this->assertSame('2026-07-01 12:00:00', $vorgang->zeitpunkt->format('Y-m-d H:i:s'));
		$this->assertSame('UTC', $vorgang->zeitpunkt->getTimezone()->getName());
	}

	public function testEinGeloeschterKursNenntDieZahlDerAnmeldungen(): void {
		$vorgang = Vorgang::geloescht(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			kennung: 'Anfängerkurs 12./13.09.2026',
			kurstag: '2026-09-13',
			anmeldungId: 19,
			wartelisteId: 18,
			anmeldungen: 6,
		);

		$this->assertSame('kurs.geloescht', $vorgang->vorgang);
		$this->assertSame(6, $vorgang->anmeldungen);
		$this->assertSame(19, $vorgang->anmeldungId);
	}

	/**
	 * Genau dieser Fall wird spaeter gesucht. Ein Protokoll, das nur Erfolge
	 * kennt, schweigt dann, wenn man es braucht.
	 */
	public function testEinAbgebrochenesLoeschenNenntWasSteckenblieb(): void {
		$vorgang = Vorgang::loeschenAbgebrochen(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			kennung: 'Anfängerkurs 12./13.09.2026',
			kurstag: '2026-09-13',
			anmeldungId: 19,
			wartelisteId: 18,
			grund: 'Forms antwortete mit 500.',
		);

		$this->assertSame('kurs.loeschen_abgebrochen', $vorgang->vorgang);
		$this->assertSame(19, $vorgang->anmeldungId);
		$this->assertSame(18, $vorgang->wartelisteId);
		$this->assertNotNull($vorgang->grund);
	}

	/**
	 * Jede Art in ihrer eigenen Spalte.
	 *
	 * Nicht immer die WARTELISTEN-Spalte, auch wenn die Anmeldung zuerst
	 * geht: Die Loeschschleife bricht beim ersten Fehler nicht ab, sondern
	 * laeuft durch und sammelt ein. Die Anmeldung kann also genauso gut
	 * steckenbleiben - ihre id gehoert dann nicht in die fremde Spalte.
	 */
	public function testEineSteckengebliebeneAnmeldungStehtNichtBeiDerWarteliste(): void {
		$vorgang = Vorgang::loeschenAbgebrochen(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			kennung: 'Anfängerkurs 12./13.09.2026',
			kurstag: '2026-09-13',
			anmeldungId: 19,
			wartelisteId: null,
			grund: 'Forms antwortete mit 500.',
		);

		$this->assertSame(19, $vorgang->anmeldungId);
		$this->assertNull($vorgang->wartelisteId);
	}

	/**
	 * BEIDE Kennungen stehen darin. Nur eine liesse den Vorgang spaeter
	 * nicht mehr zuordnen: Wer sucht, kennt entweder den alten Titel oder
	 * den neuen, nie beide.
	 */
	public function testEinVerschobenerKursNenntBeideKennungen(): void {
		$vorgang = Vorgang::verschoben(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			alteKennung: 'Anfängerkurs 12./13.09.2026',
			neueKennung: 'Anfängerkurs 14./15.11.2026',
			alterKurstag: '2026-09-13',
			neuerKurstag: '2026-11-15',
			anmeldungen: 6,
		);

		$this->assertSame('kurs.verschoben', $vorgang->vorgang);
		$this->assertStringContainsString('12./13.09.2026', $vorgang->kennung);
		$this->assertStringContainsString('14./15.11.2026', $vorgang->kennung);
		$this->assertSame('2026-11-15', $vorgang->kurstag);
		$this->assertSame(6, $vorgang->anmeldungen);
	}

	public function testEineZurueckgerollteVerschiebungVerlangtNichts(): void {
		$vorgang = Vorgang::verschiebungZurueckgerollt(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			alteKennung: 'Anfängerkurs 12./13.09.2026',
			neueKennung: 'Anfängerkurs 14./15.11.2026',
			grund: 'Schritt 3 von 4 fehlgeschlagen',
		);

		$this->assertSame('kurs.verschieben_zurueckgerollt', $vorgang->vorgang);
		$this->assertNotNull($vorgang->grund);
	}

	public function testEinAbgebrochenesVerschiebenNenntWasStehenblieb(): void {
		$vorgang = Vorgang::verschiebenAbgebrochen(
			jetzt: $this->zeitpunkt(),
			benutzer: 'anna',
			alteKennung: 'Anfängerkurs 12./13.09.2026',
			neueKennung: 'Anfängerkurs 14./15.11.2026',
			stehengeblieben: 'Anmeldung, Warteliste',
			grund: 'Das Zurückschreiben misslang.',
		);

		$this->assertSame('kurs.verschieben_abgebrochen', $vorgang->vorgang);
		$this->assertStringContainsString('Anmeldung', (string)$vorgang->grund);
	}
}
