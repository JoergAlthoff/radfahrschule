<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Benachrichtigung\Nachricht;
use OCA\Radfahrschule\Fachlogik\Benachrichtigen;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Tests\Benachrichtigung\VersandDoppel;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Protokoll\ProtokollDoppel;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

final class BenachrichtigenTest extends TestCase {
	use MitTitelmuster;

	private ProtokollDoppel $protokoll;
	private VersandDoppel $versand;

	protected function setUp(): void {
		$this->protokoll = new ProtokollDoppel();
		$this->versand = new VersandDoppel();
	}

	/** @return list<Frage> die Fragen, die eine Vorlage traegt */
	private function fragen(bool $mitEmail = true): array {
		$fragen = [
			new Frage(1, 'anrede', 'Anrede', ''),
			new Frage(2, 'vorname', 'Vorname', ''),
			new Frage(3, 'nachname', 'Nachname', ''),
		];
		if ($mitEmail) {
			$fragen[] = new Frage(4, 'email', 'E-Mail', '');
		}
		return $fragen;
	}

	private function bestand(bool $anmeldungMitEmail = true): FormulareDoppel {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 0, fragen: $this->fragen($anmeldungMitEmail)),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 1, ablauf: 0, fragen: $this->fragen()),
		]);
		$doppel->setzeEmpfaenger(19, [
			new Empfaenger('Frau', 'Erika', 'Muster', 'erika.muster@example.org'),
			new Empfaenger('', 'Max', 'Probe', 'max.probe@example.org'),
		]);
		$doppel->setzeEmpfaenger(18, [
			new Empfaenger('Herr', 'Otto', 'Warte', 'otto.warte@example.org'),
		]);
		return $doppel;
	}

	private function kurs(FormulareDoppel $doppel): Kurs {
		return Kursliste::ausFormularen($doppel->alleEigenen(), $this->titelmuster())[0];
	}

	private function nachricht(string $text): Nachricht {
		return new Nachricht('Hinweis zum {kursart}', $text, 'Anfängerkurs', '12./13.09.2026');
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	private function benachrichtigen(FormulareDoppel $doppel): Benachrichtigen {
		return new Benachrichtigen($doppel, $this->versand, $this->protokoll, $this->betreiberangaben());
	}

	public function testJedeAdresseBekommtGenauEineMail(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->benachrichtigen($doppel)->verschicke($this->kurs($doppel),
			$this->nachricht('Angemeldet'), $this->nachricht('Wartend'), 'anna', $this->jetzt());

		$adressen = $this->versand->adressen();
		sort($adressen);
		$this->assertSame(
			['erika.muster@example.org', 'max.probe@example.org', 'otto.warte@example.org'],
			$adressen);
		$this->assertSame(3, $ergebnis->verschickt);
	}

	/** Die Angemeldeten bekommen ihren Text, die Wartenden ihren. */
	public function testJedeListeBekommtIhrenText(): void {
		$doppel = $this->bestand();

		$this->benachrichtigen($doppel)->verschicke($this->kurs($doppel),
			$this->nachricht('Guten Tag {anrede} {nachname}'), $this->nachricht('Warteliste {vorname}'),
			'anna', $this->jetzt());

		$this->assertSame('Guten Tag Frau Muster', $this->versand->nachrichtAn('erika.muster@example.org')?->text);
		$this->assertSame('Guten Tag Probe', $this->versand->nachrichtAn('max.probe@example.org')?->text);
		$this->assertSame('Warteliste Otto', $this->versand->nachrichtAn('otto.warte@example.org')?->text);
		$this->assertSame('Hinweis zum Anfängerkurs', $this->versand->nachrichtAn('otto.warte@example.org')?->betreff);
	}

	/** Ein leerer Text heisst: Diese Liste bekommt nichts. */
	public function testEinLeererTextLaesstDieListeAus(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->benachrichtigen($doppel)->verschicke($this->kurs($doppel),
			$this->nachricht('Angemeldet'), $this->nachricht(''), 'anna', $this->jetzt());

		$this->assertNotContains('otto.warte@example.org', $this->versand->adressen());
		$this->assertSame(2, $ergebnis->verschickt);
		// Die leere Liste wird gar nicht erst gelesen.
		$this->assertNotContains('empfaenger:18', $doppel->aufrufe);
	}

	/** Ein Kurs ohne Wartende ist kein Fehler. */
	public function testEineLeereWartelisteIstKeinFehler(): void {
		$doppel = $this->bestand();
		$doppel->setzeEmpfaenger(18, []);

		$ergebnis = $this->benachrichtigen($doppel)->verschicke($this->kurs($doppel),
			$this->nachricht('Angemeldet'), $this->nachricht('Wartend'), 'anna', $this->jetzt());

		$this->assertSame(2, $ergebnis->verschickt);
		$this->assertSame([], $ergebnis->gescheitert);
	}

	public function testEineScheiterndeMailHaeltDieAnderenNichtAuf(): void {
		$doppel = $this->bestand();
		$this->versand->laessScheiternBei('erika.muster@example.org');

		$ergebnis = $this->benachrichtigen($doppel)->verschicke($this->kurs($doppel),
			$this->nachricht('Angemeldet'), $this->nachricht('Wartend'), 'anna', $this->jetzt());

		$this->assertSame(2, $ergebnis->verschickt);
		$this->assertSame(['Erika Muster'], $ergebnis->gescheitert);
		$this->assertContains('max.probe@example.org', $this->versand->adressen());
	}

	/**
	 * Erst alle Adressen, dann die erste Mail. Scheitert das Lesen der
	 * Warteliste, darf noch keine Mail an die Angemeldeten hinaus sein -
	 * sonst haette die Haelfte eine Nachricht und die Seite zeigte einen
	 * Fehler.
	 */
	public function testScheitertDasLesenGehtKeineMailHinaus(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('empfaenger:18');

		try {
			$this->benachrichtigen($doppel)->verschicke($this->kurs($doppel),
				$this->nachricht('Angemeldet'), $this->nachricht('Wartend'), 'anna', $this->jetzt());
			$this->fail('Das Lesen der Warteliste haette scheitern muessen.');
		} catch (FormulareNichtErreichbar) {
		}

		$this->assertSame([], $this->versand->adressen());
		$this->assertNull($this->protokoll->letzter());
	}

	public function testDasProtokollBekommtNurZahlen(): void {
		$doppel = $this->bestand();
		$this->versand->laessScheiternBei('otto.warte@example.org');

		$this->benachrichtigen($doppel)->verschicke($this->kurs($doppel),
			$this->nachricht('Angemeldet'), $this->nachricht('Wartend'), 'anna', $this->jetzt());

		$vorgang = $this->protokoll->letzter();
		$this->assertNotNull($vorgang);
		$this->assertSame('kurs.benachrichtigt', $vorgang->vorgang);
		$this->assertSame('verschickt: 2, gescheitert: 1', $vorgang->grund);
		$this->assertStringNotContainsString('Otto', (string)$vorgang->grund);
	}

	public function testOhneEmailFrageNenntDieAppWasFehlt(): void {
		$doppel = $this->bestand(anmeldungMitEmail: false);

		$fehlt = $this->benachrichtigen($doppel)->fehlendeAngabe($this->kurs($doppel));

		$this->assertNotNull($fehlt);
		$this->assertStringContainsString('„email"', $fehlt);
		$this->assertStringContainsString('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', $fehlt);
	}

	public function testMitEmailFragenFehltNichts(): void {
		$doppel = $this->bestand();

		$this->assertNull($this->benachrichtigen($doppel)->fehlendeAngabe($this->kurs($doppel)));
	}

	public function testDasAbschaltenKommtVomVersand(): void {
		$abgeschaltet = new Benachrichtigen($this->bestand(), new VersandDoppel(abgeschaltet: true),
			$this->protokoll, $this->betreiberangaben());

		$this->assertTrue($abgeschaltet->versandIstAbgeschaltet());
		$this->assertFalse($this->benachrichtigen($this->bestand())->versandIstAbgeschaltet());
	}

	/** Kursart und Termin kommen aus Einstellung und Kurs, nicht vom Aufrufer. */
	public function testDieNachrichtKenntKursartUndTermin(): void {
		$doppel = $this->bestand();

		$nachricht = $this->benachrichtigen($doppel)->nachricht($this->kurs($doppel),
			'Der {kursart} am {termin} fällt aus', 'Text');
		$persoenlich = $nachricht->fuer(new Empfaenger('', 'Erika', 'Muster', 'erika.muster@example.org'));

		$this->assertSame('Der Anfängerkurs am 12./13.09.2026 fällt aus', $persoenlich->betreff);
	}

	/** Der neue Termin kommt vom Aufrufer, der bisherige aus dem Kurs. */
	public function testDieNachrichtKenntDenNeuenTermin(): void {
		$doppel = $this->bestand();

		$nachricht = $this->benachrichtigen($doppel)->nachricht($this->kurs($doppel),
			'Verschoben', 'Neu: {neuer_termin}, bisher: {termin}', '14./15.11.2026');
		$persoenlich = $nachricht->fuer(new Empfaenger('', 'Erika', 'Muster', 'erika.muster@example.org'));

		$this->assertSame('Neu: 14./15.11.2026, bisher: 12./13.09.2026', $persoenlich->text);
	}

	/**
	 * Das Sammeln liest nur. Beim Loeschen liegt zwischen Sammeln und
	 * Verschicken das Loeschen selbst - ginge hier schon eine Mail hinaus,
	 * kaeme sie auch dann an, wenn das Loeschen scheitert.
	 */
	public function testDasSammelnVerschicktNichts(): void {
		$doppel = $this->bestand();

		$auftraege = $this->benachrichtigen($doppel)->auftraege($this->kurs($doppel),
			$this->nachricht('Angemeldet'), $this->nachricht('Wartend'));

		$this->assertCount(3, $auftraege);
		$this->assertSame([], $this->versand->adressen());
		$this->assertNull($this->protokoll->letzter());
	}

	public function testSchickeVerschicktDieGesammeltenAuftraege(): void {
		$doppel = $this->bestand();
		$benachrichtigen = $this->benachrichtigen($doppel);
		$kurs = $this->kurs($doppel);
		$auftraege = $benachrichtigen->auftraege($kurs,
			$this->nachricht('Angemeldet {vorname}'), $this->nachricht(''));

		$ergebnis = $benachrichtigen->schicke($kurs, $auftraege, 'anna', $this->jetzt());

		$this->assertSame(2, $ergebnis->verschickt);
		$this->assertSame('Angemeldet Erika', $this->versand->nachrichtAn('erika.muster@example.org')?->text);
		$this->assertSame('kurs.benachrichtigt', $this->protokoll->letzter()?->vorgang);
	}
}
