<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\NachrueckenController;
use OCA\Radfahrschule\Fachlogik\Nachruecken;
use OCA\Radfahrschule\Formulare\Abgabe;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCA\Radfahrschule\Sperre\Schreibsperre;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Protokoll\ProtokollDoppel;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NachrueckenControllerTest extends TestCase {
	use MitTitelmuster;

	private const KENNUNG = 'Anfängerkurs 12./13.09.2026';
	private const ADRESSE_ERGEBNIS = '/apps/radfahrschule/nachgerueckt';
	private const ADRESSE_UEBERSICHT = '/apps/radfahrschule/';

	/**
	 * Der Inhalt der Sitzung. Alle Controller eines Tests teilen ihn, wie
	 * zwei Seitenaufrufe desselben Browsers.
	 *
	 * @var array<string, mixed>
	 */
	private array $sitzungsinhalt = [];

	private function sitzung(): ISession {
		$sitzung = $this->createStub(ISession::class);
		$sitzung->method('get')->willReturnCallback($this->liesAusDerSitzung(...));
		$sitzung->method('set')->willReturnCallback($this->schreibInDieSitzung(...));
		$sitzung->method('remove')->willReturnCallback($this->entferneAusDerSitzung(...));
		return $sitzung;
	}

	private function liesAusDerSitzung(string $schluessel): mixed {
		return $this->sitzungsinhalt[$schluessel] ?? null;
	}

	private function schreibInDieSitzung(string $schluessel, mixed $wert): void {
		$this->sitzungsinhalt[$schluessel] = $wert;
	}

	private function entferneAusDerSitzung(string $schluessel): void {
		unset($this->sitzungsinhalt[$schluessel]);
	}

	/** Jede Route hat ihre eigene Adresse, sonst faellt ein falsches Ziel nicht auf. */
	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnMap([
			['radfahrschule.nachruecken.ergebnis', [], self::ADRESSE_ERGEBNIS],
			['radfahrschule.uebersicht.index', [], self::ADRESSE_UEBERSICHT],
		]);
		return $urlGenerator;
	}

	private function bestand(int $belegt = 8): FormulareDoppel {
		$fragen = [new Frage(3, 'vorname', 'Vorname', ''), new Frage(4, 'nachname', 'Nachname', '')];
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — ' . self::KENNUNG,
				beschreibung: '', abgaben: $belegt, ablauf: 0, fragen: $fragen, platzzahl: 10),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — ' . self::KENNUNG,
				beschreibung: '', abgaben: 3, ablauf: 0),
		]);
		// 1788256800 ist der 01.09.2026 um 10:00 Uhr UTC.
		$doppel->setzeAbgaben(18, [
			new Abgabe(501, 1788256800, ['vorname' => ['Anna'], 'nachname' => ['Erste']]),
			new Abgabe(502, 1788343200, ['vorname' => ['Berta'], 'nachname' => ['Zweite']]),
			new Abgabe(503, 1788429600, []),
		]);
		return $doppel;
	}

	/** @param array<string, mixed> $felder */
	private function controller(
		?FormulareDoppel $doppel = null,
		array $felder = [],
		bool $darfVerwalten = true,
	): NachrueckenController {
		$doppel ??= $this->bestand();

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $feld, mixed $vorgabe = null): mixed => $felder[$feld] ?? $vorgabe);

		$recht = $this->createStub(Verwaltungsrecht::class);
		$recht->method('darfVerwalten')->willReturn($darfVerwalten);
		$recht->method('benutzer')->willReturn('anna');

		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(
			new DateTimeImmutable('2026-09-05 10:00:00', new DateTimeZone('UTC')));

		$sperre = new Schreibsperre(
			$this->createStub(ILockingProvider::class), $this->createStub(LoggerInterface::class));

		return new NachrueckenController(
			'radfahrschule',
			$request,
			$doppel,
			new Nachruecken($doppel, new ProtokollDoppel(), $sperre),
			$recht,
			$timeFactory,
			$this->createStub(INavigationManager::class),
			$this->titelmuster(),
			$this->zeitzone(),
			$this->sitzung(),
			$this->urlGenerator(),
		);
	}

	public function testDieSeiteZeigtPlaetzeUndWarteliste(): void {
		$antwort = $this->controller(felder: ['kennung' => self::KENNUNG])->seite();

		$this->assertSame('nachruecken', $antwort->getTemplateName());
		$daten = $antwort->getParams();
		$this->assertSame(self::KENNUNG, $daten['kennung']);
		$this->assertSame('8 von 10 Plätzen sind belegt. Frei: 2.', $daten['platzsatz']);
		$this->assertSame('', $daten['hindernis']);
		$this->assertSame('', $daten['fehler']);
		$this->assertSame(
			['nummer' => 501, 'name' => 'Anna Erste', 'eingetragen' => '01.09.2026', 'angekreuzt' => false],
			$daten['zeilen'][0]);
		$this->assertCount(3, $daten['zeilen']);
	}

	public function testEineAbgabeOhneNamenBekommtEinenErsatz(): void {
		$antwort = $this->controller(felder: ['kennung' => self::KENNUNG])->seite();

		$this->assertSame('(ohne Namen)', $antwort->getParams()['zeilen'][2]['name']);
	}

	public function testEinVollerKursNenntDasHindernis(): void {
		$antwort = $this->controller($this->bestand(belegt: 10), ['kennung' => self::KENNUNG])->seite();

		$this->assertSame('Es ist kein Platz frei.', $antwort->getParams()['hindernis']);
	}

	public function testOhneRechtGibtEsKeineDerDreiSeiten(): void {
		$felder = ['kennung' => self::KENNUNG, 'abgaben' => ['501']];
		$doppel = $this->bestand();

		$seite = $this->controller($doppel, $felder, darfVerwalten: false)->seite();
		$ausfuehren = $this->controller($doppel, $felder, darfVerwalten: false)->ausfuehren();
		$ergebnis = $this->controller($doppel, $felder, darfVerwalten: false)->ergebnis();

		foreach ([$seite, $ausfuehren, $ergebnis] as $antwort) {
			$this->assertInstanceOf(TemplateResponse::class, $antwort);
			$this->assertSame('meldung', $antwort->getTemplateName());
			$this->assertSame('Dafür fehlt die Berechtigung', $antwort->getParams()['titel']);
		}
		$this->assertSame([], $doppel->eingereicht);
	}

	public function testOhneKennungGibtEsEineMeldung(): void {
		$antwort = $this->controller()->seite();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Die Angaben passen nicht', $antwort->getParams()['titel']);
	}

	public function testEineUnbekannteKennungGibtEineMeldung(): void {
		$antwort = $this->controller(felder: ['kennung' => 'Gibt es nicht'])->seite();

		$this->assertSame('Unbekannter Kurs', $antwort->getParams()['titel']);
	}

	public function testNachDemNachrueckenLeitetDieAppUm(): void {
		$doppel = $this->bestand();

		$umleitung = $this->controller($doppel, ['kennung' => self::KENNUNG, 'abgaben' => ['501', '502']])
			->ausfuehren();

		$this->assertInstanceOf(RedirectResponse::class, $umleitung);
		$this->assertSame(self::ADRESSE_ERGEBNIS, $umleitung->getRedirectURL());
		$this->assertCount(2, $doppel->eingereicht[19]);
	}

	public function testDieErgebnisseiteNenntWerNachgeruecktIst(): void {
		$doppel = $this->bestand();
		$this->controller($doppel, ['kennung' => self::KENNUNG, 'abgaben' => ['501', '502']])->ausfuehren();

		$ergebnis = $this->controller($doppel)->ergebnis();

		$this->assertInstanceOf(TemplateResponse::class, $ergebnis);
		$this->assertSame('nachgerueckt', $ergebnis->getTemplateName());
		$daten = $ergebnis->getParams();
		$this->assertSame(self::KENNUNG, $daten['kennung']);
		$this->assertSame('2 Personen sind nachgerückt.', $daten['satz']);
		$this->assertSame(['Anna Erste', 'Berta Zweite'], $daten['nachgerueckt']);
		$this->assertSame('', $daten['stoerung']);
		$this->assertSame([], $daten['uebrig']);
	}

	/** Ein Neuladen der Ergebnisseite laesst niemanden ein zweites Mal nachruecken. */
	public function testDasErgebnisWirdNurEinmalGezeigt(): void {
		$doppel = $this->bestand();
		$this->controller($doppel, ['kennung' => self::KENNUNG, 'abgaben' => ['501']])->ausfuehren();
		$this->controller($doppel)->ergebnis();

		$zweiterAufruf = $this->controller($doppel)->ergebnis();

		$this->assertInstanceOf(RedirectResponse::class, $zweiterAufruf);
		$this->assertSame(self::ADRESSE_UEBERSICHT, $zweiterAufruf->getRedirectURL());
		$this->assertCount(1, $doppel->eingereicht[19]);
	}

	public function testZuVieleAusgewaehltZeigtDieSeiteMitDenHakenWieder(): void {
		$doppel = $this->bestand();

		$antwort = $this->controller($doppel, ['kennung' => self::KENNUNG, 'abgaben' => ['501', '502', '503']])
			->ausfuehren();

		$this->assertInstanceOf(TemplateResponse::class, $antwort);
		$this->assertSame('nachruecken', $antwort->getTemplateName());
		$daten = $antwort->getParams();
		$this->assertSame('3 ausgewählt, aber nur 2 Plätze frei. Bitte neu auswählen.', $daten['fehler']);
		$this->assertTrue($daten['zeilen'][0]['angekreuzt']);
		$this->assertTrue($daten['zeilen'][2]['angekreuzt']);
		$this->assertSame([], $doppel->eingereicht);
	}

	/** Das Hindernis und der Einwand sind derselbe Satz. Die Seite zeigt ihn einmal. */
	public function testDerselbeSatzStehtNurAlsHindernisDa(): void {
		$antwort = $this->controller($this->bestand(belegt: 10), ['kennung' => self::KENNUNG, 'abgaben' => ['501']])
			->ausfuehren();

		$this->assertInstanceOf(TemplateResponse::class, $antwort);
		$daten = $antwort->getParams();
		$this->assertSame('Es ist kein Platz frei.', $daten['hindernis']);
		$this->assertSame('', $daten['fehler']);
	}

	/** Kein Array, oder ein Array ohne Zahlen: wie niemand ausgewaehlt. */
	public function testUnsinnInDerAuswahlZaehltWieKeineAuswahl(): void {
		$doppel = $this->bestand();

		$keinArray = $this->controller($doppel, ['kennung' => self::KENNUNG, 'abgaben' => '501'])->ausfuehren();
		$keineZahlen = $this->controller($doppel, ['kennung' => self::KENNUNG, 'abgaben' => ['abc', '-1', ['501']]])
			->ausfuehren();

		foreach ([$keinArray, $keineZahlen] as $antwort) {
			$this->assertInstanceOf(TemplateResponse::class, $antwort);
			$this->assertSame('Es ist niemand ausgewählt.', $antwort->getParams()['fehler']);
		}
		$this->assertSame([], $doppel->eingereicht);
	}

	public function testAntwortetFormsNichtGibtEsEineMeldung(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('formularHolen:19');

		$antwort = $this->controller($doppel, ['kennung' => self::KENNUNG, 'abgaben' => ['501']])->ausfuehren();

		$this->assertInstanceOf(TemplateResponse::class, $antwort);
		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Nachrücken nicht möglich', $antwort->getParams()['titel']);
	}
}
