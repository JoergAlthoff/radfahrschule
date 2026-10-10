<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Nachruecken;
use OCA\Radfahrschule\Formulare\Abgabe;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Sperre\Schreibsperre;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Protokoll\ProtokollDoppel;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NachrueckenTest extends TestCase {
	use MitTitelmuster;

	private const ANMELDUNG = 19;
	private const WARTELISTE = 18;
	private const JETZT = '2026-09-01 10:00:00';

	private ProtokollDoppel $protokoll;

	protected function setUp(): void {
		$this->protokoll = new ProtokollDoppel();
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable(self::JETZT, new DateTimeZone('UTC'));
	}

	/**
	 * Die Fragen der Anmeldung. Das Kaestchen der Teilnahmebedingungen
	 * lautet anders als in der Warteliste.
	 *
	 * @return list<Frage>
	 */
	private function fragenDerAnmeldung(): array {
		return [
			new Frage(1, 'teilnahmebedingungen', 'Bedingungen', '', [601 => 'Ich melde mich verbindlich an.']),
			new Frage(2, 'anrede', 'Anrede', '', [602 => 'Frau', 603 => 'Herr']),
			new Frage(3, 'vorname', 'Vorname', ''),
			new Frage(4, 'nachname', 'Nachname', ''),
			new Frage(5, 'vorerfahrungen', 'Vorerfahrungen', '', [604 => 'Noch nie gefahren', 605 => 'Schon mal gefahren']),
		];
	}

	/** @param array<string, list<string>> $weitere */
	private function abgabe(int $id, int $zeitpunkt, string $vorname, string $nachname, array $weitere = []): Abgabe {
		$antworten = [
			'teilnahmebedingungen' => ['Wenn ein Platz frei wird, möchte ich teilnehmen.'],
			'anrede' => ['Frau'],
			'vorname' => [$vorname],
			'nachname' => [$nachname],
		];
		return new Abgabe($id, $zeitpunkt, array_merge($antworten, $weitere));
	}

	/**
	 * Ein Kurs mit zehn Plaetzen. Drei stehen auf der Warteliste.
	 *
	 * @param list<Frage>|null $fragen die Fragen der Anmeldung
	 */
	private function bestand(int $belegt = 8, ?int $platzzahl = 10, int $ablauf = 0, ?array $fragen = null): FormulareDoppel {
		$doppel = new FormulareDoppel([
			new Formular(id: self::ANMELDUNG, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: $belegt, ablauf: $ablauf,
				fragen: $fragen ?? $this->fragenDerAnmeldung(), platzzahl: $platzzahl),
			new Formular(id: self::WARTELISTE, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 3, ablauf: 0),
		]);
		$doppel->setzeAbgaben(self::WARTELISTE, [
			$this->abgabe(503, 3000, 'Carla', 'Dritte'),
			$this->abgabe(501, 1000, 'Anna', 'Erste'),
			$this->abgabe(502, 2000, 'Berta', 'Zweite'),
		]);
		return $doppel;
	}

	private function kurs(FormulareDoppel $doppel): Kurs {
		return Kursliste::ausFormularen($doppel->bestand(), $this->titelmuster())[0];
	}

	private function nachruecken(FormulareDoppel $doppel, ?ILockingProvider $lockingProvider = null): Nachruecken {
		$lockingProvider ??= $this->createStub(ILockingProvider::class);
		$sperre = new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class));
		return new Nachruecken($doppel, $this->protokoll, $sperre);
	}

	/** @return list<int> die Nummern, die noch auf der Warteliste stehen */
	private function nochWartend(FormulareDoppel $doppel): array {
		$nummern = [];
		foreach ($doppel->abgaben(self::WARTELISTE) as $abgabe) {
			$nummern[] = $abgabe->id;
		}
		sort($nummern);
		return $nummern;
	}

	/** @return list<string> nur die schreibenden Aufrufe */
	private function schreibendeAufrufe(FormulareDoppel $doppel): array {
		$schreibend = [];
		foreach ($doppel->aufrufe as $aufruf) {
			if (str_starts_with($aufruf, 'abgabeEinreichen') || str_starts_with($aufruf, 'abgabeLoeschen')) {
				$schreibend[] = $aufruf;
			}
		}
		return $schreibend;
	}

	public function testWerZuerstKamStehtOben(): void {
		$doppel = $this->bestand();

		$wartende = $this->nachruecken($doppel)->wartende($this->kurs($doppel));

		$this->assertSame([501, 502, 503], array_map(static fn (Abgabe $abgabe): int => $abgabe->id, $wartende));
	}

	/** Zwei Abgaben in derselben Sekunde: Die kleinere Nummer kam zuerst. */
	public function testBeiGleichemZeitpunktEntscheidetDieNummer(): void {
		$doppel = $this->bestand();
		$doppel->setzeAbgaben(self::WARTELISTE, [
			$this->abgabe(128, 1000, 'Zweite', 'Person'),
			$this->abgabe(127, 1000, 'Erste', 'Person'),
		]);

		$wartende = $this->nachruecken($doppel)->wartende($this->kurs($doppel));

		$this->assertSame([127, 128], array_map(static fn (Abgabe $abgabe): int => $abgabe->id, $wartende));
	}

	public function testDerPlatzsatzNenntBelegtUndFrei(): void {
		$doppel = $this->bestand();
		$nachruecken = $this->nachruecken($doppel);

		$satz = $nachruecken->platzsatz($nachruecken->anmeldung($this->kurs($doppel)));

		$this->assertSame('8 von 10 Plätzen sind belegt. Frei: 2.', $satz);
	}

	public function testMitFreienPlaetzenGibtEsKeinHindernis(): void {
		$doppel = $this->bestand();
		$nachruecken = $this->nachruecken($doppel);

		$this->assertNull($nachruecken->hindernis($nachruecken->anmeldung($this->kurs($doppel)), $this->jetzt()));
	}

	public function testOhnePlatzzahlIstDerPlatzsatzLeer(): void {
		$doppel = $this->bestand(platzzahl: null);
		$nachruecken = $this->nachruecken($doppel);

		$this->assertSame('', $nachruecken->platzsatz($nachruecken->anmeldung($this->kurs($doppel))));
	}

	public function testEinVollerKursIstEinHindernis(): void {
		$doppel = $this->bestand(belegt: 10);
		$nachruecken = $this->nachruecken($doppel);

		$this->assertSame('Es ist kein Platz frei.',
			$nachruecken->hindernis($nachruecken->anmeldung($this->kurs($doppel)), $this->jetzt()));
	}

	public function testOhnePlatzzahlGehtEsNicht(): void {
		$doppel = $this->bestand(platzzahl: null);
		$nachruecken = $this->nachruecken($doppel);

		$this->assertSame('Die Anmeldung hat keine Platzzahl. Bitte in Forms eintragen.',
			$nachruecken->hindernis($nachruecken->anmeldung($this->kurs($doppel)), $this->jetzt()));
	}

	/** Eine Sekunde vor dem Ablauf geht es noch, in der Sekunde des Ablaufs nicht mehr. */
	public function testDerAnmeldeschlussIstDieGrenze(): void {
		$ablauf = $this->jetzt()->getTimestamp();
		$doppel = $this->bestand(ablauf: $ablauf);
		$nachruecken = $this->nachruecken($doppel);
		$anmeldung = $nachruecken->anmeldung($this->kurs($doppel));

		$this->assertNull($nachruecken->hindernis($anmeldung, $this->jetzt()->modify('-1 second')));
		$this->assertSame('Der Anmeldeschluss ist vorbei. Nachrücken geht nicht mehr.',
			$nachruecken->hindernis($anmeldung, $this->jetzt()));
	}

	public function testZweiRueckenNachInDerReihenfolgeDerWarteliste(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [502, 501], 'anna', $this->jetzt());

		$this->assertNull($ergebnis->einwand);
		$this->assertSame(['Anna Erste', 'Berta Zweite'], $ergebnis->nachgerueckt);
		$this->assertSame('', $ergebnis->stoerung);
		$this->assertSame([], $ergebnis->uebrig);
		$this->assertSame('2 Personen sind nachgerückt.', $ergebnis->satz());
		$this->assertSame([503], $this->nochWartend($doppel));
		// Erst einreichen, dann loeschen, und das je Person.
		$this->assertSame([
			'abgabeEinreichen:19', 'abgabeLoeschen:18/501',
			'abgabeEinreichen:19', 'abgabeLoeschen:18/502',
		], $this->schreibendeAufrufe($doppel));
	}

	/**
	 * Textfragen gehen unveraendert hinueber, Auswahlfragen als Nummer der
	 * Anmeldung. Das einzelne Kaestchen wird angekreuzt, obwohl sein Text in
	 * der Warteliste anders lautet.
	 */
	public function testDieAntwortenWerdenFuerDieAnmeldungUebersetzt(): void {
		$doppel = $this->bestand();

		$this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertSame([[
			1 => ['601'],
			2 => ['602'],
			3 => ['Anna'],
			4 => ['Erste'],
		]], $doppel->eingereicht[self::ANMELDUNG]);
	}

	/** Eine Antwort, die keine der Auswahlmoeglichkeiten ist, hat die Person selbst getippt. */
	public function testEineEigeneAntwortBekommtDasKennzeichenVonForms(): void {
		$doppel = $this->bestand();
		$doppel->setzeAbgaben(self::WARTELISTE, [
			$this->abgabe(501, 1000, 'Anna', 'Erste', ['anrede' => ['Divers']]),
		]);

		$this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertSame(['system-other-answer:Divers'], $doppel->eingereicht[self::ANMELDUNG][0][2]);
	}

	public function testZweiAntwortenAufEineFrageKommenBeideAn(): void {
		$doppel = $this->bestand();
		$doppel->setzeAbgaben(self::WARTELISTE, [
			$this->abgabe(501, 1000, 'Anna', 'Erste',
				['vorerfahrungen' => ['Noch nie gefahren', 'Schon mal gefahren']]),
		]);

		$this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertSame(['604', '605'], $doppel->eingereicht[self::ANMELDUNG][0][5]);
	}

	public function testDieselbeNummerZweimalRuecktEinmalNach(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 501], 'anna', $this->jetzt());

		$this->assertSame(['Anna Erste'], $ergebnis->nachgerueckt);
		$this->assertCount(1, $doppel->eingereicht[self::ANMELDUNG]);
	}

	public function testEinGelungenerLaufStehtImProtokoll(): void {
		$doppel = $this->bestand();

		$this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501, 502], 'anna', $this->jetzt());

		$vorgang = $this->protokoll->letzter();
		$this->assertSame('kurs.nachgerueckt', $vorgang?->vorgang);
		$this->assertSame('anna', $vorgang?->benutzer);
		$this->assertSame('Anfängerkurs 12./13.09.2026', $vorgang?->kennung);
		$this->assertSame('nachgerückt: 2, gescheitert: 0, nicht mehr drangekommen: 0', $vorgang?->grund);
	}

	public function testZuVieleAusgewaehltUndNiemandRuecktNach(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 502, 503], 'anna', $this->jetzt());

		$this->assertSame('3 ausgewählt, aber nur 2 Plätze frei. Bitte neu auswählen.', $ergebnis->einwand);
		$this->assertSame([], $this->schreibendeAufrufe($doppel));
		$this->assertNull($this->protokoll->letzter());
	}

	/** Genau so viele wie frei sind, das geht. */
	public function testGenauSoVieleWieFreiSindGehenDurch(): void {
		$doppel = $this->bestand(belegt: 7);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 502, 503], 'anna', $this->jetzt());

		$this->assertNull($ergebnis->einwand);
		$this->assertCount(3, $ergebnis->nachgerueckt);
	}

	public function testBeiEinemFreienPlatzStehtDieEinzahl(): void {
		$doppel = $this->bestand(belegt: 9);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 502], 'anna', $this->jetzt());

		$this->assertSame('2 ausgewählt, aber nur 1 Platz frei. Bitte neu auswählen.', $ergebnis->einwand);
	}

	public function testOhneAuswahlGibtEsEinenEinwand(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [], 'anna', $this->jetzt());

		$this->assertSame('Es ist niemand ausgewählt.', $ergebnis->einwand);
	}

	public function testEineNummerDieNichtMehrWartetIstEinEinwand(): void {
		$doppel = $this->bestand();

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 999], 'anna', $this->jetzt());

		$this->assertSame('Die Warteliste hat sich geändert. Bitte neu auswählen.', $ergebnis->einwand);
		$this->assertSame([], $this->schreibendeAufrufe($doppel));
	}

	public function testNachDemAnmeldeschlussRuecktNiemandNach(): void {
		$doppel = $this->bestand(ablauf: $this->jetzt()->getTimestamp() - 60);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertSame('Der Anmeldeschluss ist vorbei. Nachrücken geht nicht mehr.', $ergebnis->einwand);
		$this->assertSame([], $this->schreibendeAufrufe($doppel));
	}

	private const SATZ_OHNE_FRAGENAME = 'Die Warteliste hat eine Frage ohne technischen Namen. '
		. 'Die App kann ihre Antwort nicht übertragen. Bitte den Namen in Forms eintragen.';

	public function testEineAntwortOhneFragennamenRuecktNiemandNach(): void {
		$doppel = $this->bestand();
		$doppel->setzeAbgaben(self::WARTELISTE, [
			new Abgabe(501, 1000, ['vorname' => ['Anna']], antwortenOhneFragenname: 1),
		]);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertSame(self::SATZ_OHNE_FRAGENAME, $ergebnis->einwand);
		$this->assertSame([], $this->schreibendeAufrufe($doppel));
	}

	public function testEineAntwortOhneFragennamenBeiEinemNichtAusgewaehltenStoertNicht(): void {
		$doppel = $this->bestand();
		$doppel->setzeAbgaben(self::WARTELISTE, [
			$this->abgabe(501, 1000, 'Anna', 'Erste'),
			new Abgabe(502, 2000, ['vorname' => ['Berta']], antwortenOhneFragenname: 1),
		]);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertNull($ergebnis->einwand);
		$this->assertSame(['Anna Erste'], $ergebnis->nachgerueckt);
	}

	public function testFehltDerAnmeldungEineFrageRuecktNiemandNach(): void {
		$ohneNachname = [
			new Frage(1, 'teilnahmebedingungen', 'Bedingungen', '', [601 => 'Ich melde mich verbindlich an.']),
			new Frage(2, 'anrede', 'Anrede', '', [602 => 'Frau', 603 => 'Herr']),
			new Frage(3, 'vorname', 'Vorname', ''),
		];
		$doppel = $this->bestand(fragen: $ohneNachname);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertSame('Die Formulare passen nicht zusammen. Der Anmeldung fehlt die Frage „nachname".',
			$ergebnis->einwand);
		$this->assertSame([], $this->schreibendeAufrufe($doppel));
	}

	/**
	 * Zwischen dem Anzeigen und dem Klick hat sich jemand ueber das
	 * oeffentliche Formular angemeldet. Die erste Person ist durch, die
	 * zweite lehnt Forms ab, die dritte kommt nicht mehr dran.
	 */
	public function testLehntFormsAbHaeltDerLaufAn(): void {
		$doppel = $this->bestand(belegt: 7);
		$doppel->laessEinreichenAblehnenAb(2);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 502, 503], 'anna', $this->jetzt());

		$this->assertSame(['Anna Erste'], $ergebnis->nachgerueckt);
		$this->assertSame('Berta Zweite ist nicht nachgerückt. Forms hat die Anmeldung nicht angenommen. '
			. 'Wahrscheinlich ist der Platz inzwischen vergeben.', $ergebnis->stoerung);
		$this->assertSame(['Carla Dritte'], $ergebnis->uebrig);
		$this->assertSame([502, 503], $this->nochWartend($doppel));
		$this->assertSame('kurs.nachruecken_abgebrochen', $this->protokoll->letzter()?->vorgang);
		$this->assertSame('nachgerückt: 1, gescheitert: 1, nicht mehr drangekommen: 1',
			$this->protokoll->letzter()?->grund);
	}

	/**
	 * Die Abgabe kommt an, die Antwort geht verloren. Die App weiss es
	 * nicht und loescht deshalb nichts.
	 */
	public function testOhneAntwortBleibtDerEintragAufDerWarteliste(): void {
		$doppel = $this->bestand();
		$doppel->laessNachDerWirkungScheitern('abgabeEinreichen:19');

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 502], 'anna', $this->jetzt());

		$this->assertSame([], $ergebnis->nachgerueckt);
		$this->assertSame('Bei Anna Erste ist nicht sicher, ob die Anmeldung angekommen ist. '
			. 'Bitte in Forms nachsehen.', $ergebnis->stoerung);
		$this->assertSame(['Berta Zweite'], $ergebnis->uebrig);
		$this->assertSame([501, 502, 503], $this->nochWartend($doppel));
	}

	public function testScheitertDasLoeschenStehtDiePersonInBeidenListen(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('abgabeLoeschen:18/501');

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken(
			$this->kurs($doppel), [501, 502], 'anna', $this->jetzt());

		$this->assertSame(['Anna Erste'], $ergebnis->nachgerueckt);
		$this->assertSame('Anna Erste ist angemeldet, steht aber noch auf der Warteliste. '
			. 'Bitte dort von Hand löschen.', $ergebnis->stoerung);
		$this->assertSame(['Berta Zweite'], $ergebnis->uebrig);
		$this->assertSame('1 Person ist nachgerückt.', $ergebnis->satz());
	}

	public function testEineBelegteSperreWeistAb(): void {
		$doppel = $this->bestand();
		$lockingProvider = $this->createStub(ILockingProvider::class);
		$lockingProvider->method('acquireLock')->willThrowException(new LockedException('radfahrschule'));

		$this->expectException(GeradeBeschaeftigt::class);

		$this->nachruecken($doppel, $lockingProvider)->lasseNachruecken(
			$this->kurs($doppel), [501], 'anna', $this->jetzt());
	}

	public function testNiemandNachgeruecktHatEinenEigenenSatz(): void {
		$doppel = $this->bestand();
		$doppel->laessEinreichenAblehnenAb(1);

		$ergebnis = $this->nachruecken($doppel)->lasseNachruecken($this->kurs($doppel), [501], 'anna', $this->jetzt());

		$this->assertSame('Niemand ist nachgerückt.', $ergebnis->satz());
	}
}
