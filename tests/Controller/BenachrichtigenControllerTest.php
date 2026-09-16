<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\BenachrichtigenController;
use OCA\Radfahrschule\Fachlogik\Benachrichtigen;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCA\Radfahrschule\Tests\Benachrichtigung\VersandDoppel;
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
use PHPUnit\Framework\TestCase;

final class BenachrichtigenControllerTest extends TestCase {
	use MitTitelmuster;

	private const KENNUNG = 'Anfängerkurs 12./13.09.2026';
	private const ADRESSE_ERGEBNIS = '/apps/radfahrschule/nachricht-verschickt';
	private const ADRESSE_UEBERSICHT = '/apps/radfahrschule/';

	private VersandDoppel $versand;

	/**
	 * Der Inhalt der Sitzung. Alle Controller eines Tests teilen ihn, wie
	 * zwei Seitenaufrufe desselben Browsers.
	 *
	 * @var array<string, mixed>
	 */
	private array $sitzungsinhalt = [];

	protected function setUp(): void {
		$this->versand = new VersandDoppel();
	}

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
			['radfahrschule.benachrichtigen.verschickt', [], self::ADRESSE_ERGEBNIS],
			['radfahrschule.uebersicht.index', [], self::ADRESSE_UEBERSICHT],
		]);
		return $urlGenerator;
	}

	/** @return list<Frage> */
	private function fragen(bool $mitEmail): array {
		$fragen = [new Frage(2, 'vorname', 'Vorname', ''), new Frage(3, 'nachname', 'Nachname', '')];
		if ($mitEmail) {
			$fragen[] = new Frage(4, 'email', 'E-Mail', '');
		}
		return $fragen;
	}

	private function bestand(bool $mitEmail = true): FormulareDoppel {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — ' . self::KENNUNG,
				beschreibung: '', abgaben: 1, ablauf: 0, fragen: $this->fragen($mitEmail)),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — ' . self::KENNUNG,
				beschreibung: '', abgaben: 1, ablauf: 0, fragen: $this->fragen($mitEmail)),
		]);
		$doppel->setzeEmpfaenger(19, [new Empfaenger('Frau', 'Erika', 'Muster', 'erika.muster@example.org')]);
		$doppel->setzeEmpfaenger(18, [new Empfaenger('', 'Otto', 'Warte', 'otto.warte@example.org')]);
		return $doppel;
	}

	/** @param array<string, string> $felder */
	private function controller(
		?FormulareDoppel $doppel = null,
		array $felder = [],
		bool $darfVerwalten = true,
	): BenachrichtigenController {
		$doppel ??= $this->bestand();

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $feld, mixed $vorgabe = null): mixed => $felder[$feld] ?? $vorgabe);

		$recht = $this->createStub(Verwaltungsrecht::class);
		$recht->method('darfVerwalten')->willReturn($darfVerwalten);
		$recht->method('benutzer')->willReturn('anna');

		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(
			new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC')));

		return new BenachrichtigenController(
			'radfahrschule',
			$request,
			$doppel,
			new Benachrichtigen($doppel, $this->versand, new ProtokollDoppel(), $this->betreiberangaben()),
			$recht,
			$timeFactory,
			$this->createStub(INavigationManager::class),
			$this->titelmuster(),
			$this->zeitzone(),
			$this->sitzung(),
			$this->urlGenerator(),
		);
	}

	/**
	 * Schickt ab und folgt der Umleitung, wie es der Browser tut.
	 *
	 * @param array<string, string> $felder
	 */
	private function sendeUndFolge(array $felder): TemplateResponse {
		$umleitung = $this->controller(felder: $felder)->sende();
		$this->assertInstanceOf(RedirectResponse::class, $umleitung);

		$ergebnisseite = $this->controller()->verschickt();
		$this->assertInstanceOf(TemplateResponse::class, $ergebnisseite);
		return $ergebnisseite;
	}

	/**
	 * Die Schreibseite zeigt Zahlen und liest keine Adresse. Die Zahl steht
	 * im Formular selbst.
	 */
	public function testDieSeiteNenntDieZahlenUndLiestKeineAdressen(): void {
		$doppel = $this->bestand();

		$antwort = $this->controller($doppel, ['kennung' => self::KENNUNG])->formular();

		$this->assertSame('benachrichtigung', $antwort->getTemplateName());
		$daten = $antwort->getParams();
		$this->assertSame(self::KENNUNG, $daten['kennung']);
		$this->assertSame(
			[['beschriftung' => 'Anmeldung', 'eintraege' => 1],
				['beschriftung' => 'Warteliste', 'eintraege' => 1]],
			$daten['zeilen']);
		$this->assertSame([], preg_grep('/^empfaenger:/', $doppel->aufrufe));
	}

	public function testOhneRechtGibtEsDieSeiteNicht(): void {
		$antwort = $this->controller(felder: ['kennung' => self::KENNUNG], darfVerwalten: false)->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertArrayNotHasKey('kennung', $antwort->getParams());
	}

	/**
	 * Der fehlende Knopf ist keine Sicherung: Den POST kann jeder von Hand
	 * schicken.
	 */
	public function testOhneRechtGehtKeineMailHinaus(): void {
		$this->controller(felder: [
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis', 'textAngemeldete' => 'Text',
		], darfVerwalten: false)->sende();

		$this->assertSame([], $this->versand->adressen());
	}

	public function testBeiAbgeschaltetemVersandSagtEsDieSeiteVorher(): void {
		$doppel = $this->bestand();
		$this->versand = new VersandDoppel(abgeschaltet: true);

		$antwort = $this->controller($doppel, ['kennung' => self::KENNUNG])->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('abgeschaltet', $antwort->getParams()['meldung']);
	}

	public function testOhneEmailFrageNenntDieSeiteDenNamen(): void {
		$antwort = $this->controller($this->bestand(mitEmail: false), ['kennung' => self::KENNUNG])->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('„email"', $antwort->getParams()['meldung']);
	}

	public function testEinUnbekannterKursGibtEineMeldung(): void {
		$antwort = $this->controller(felder: ['kennung' => 'Anfängerkurs 01./02.01.2030'])->formular();

		$this->assertSame('Unbekannter Kurs', $antwort->getParams()['titel']);
	}

	public function testDerVersandSetztKursartUndTerminEin(): void {
		$antwort = $this->sendeUndFolge([
			'kennung' => self::KENNUNG,
			'betreff' => 'Zum {kursart} am {termin}',
			'textAngemeldete' => 'Guten Tag {anrede} {nachname}',
			'textWartende' => 'Hallo {vorname}',
		]);

		$this->assertSame('benachrichtigt', $antwort->getTemplateName());
		$this->assertSame('2 Nachrichten sind hinausgegangen.', $antwort->getParams()['satz']);
		$this->assertSame('Zum Anfängerkurs am 12./13.09.2026',
			$this->versand->nachrichtAn('erika.muster@example.org')?->betreff);
		$this->assertSame('Guten Tag Frau Muster',
			$this->versand->nachrichtAn('erika.muster@example.org')?->text);
		$this->assertSame('Hallo Otto', $this->versand->nachrichtAn('otto.warte@example.org')?->text);
	}

	/** Ohne Betreff geht nichts hinaus, und das Getippte bleibt stehen. */
	public function testOhneBetreffBleibtDerTextStehen(): void {
		$antwort = $this->controller(felder: [
			'kennung' => self::KENNUNG, 'betreff' => '', 'textAngemeldete' => 'Mein langer Text',
		])->sende();

		$this->assertSame('benachrichtigung', $antwort->getTemplateName());
		$this->assertSame('Mein langer Text', $antwort->getParams()['textAngemeldete']);
		$this->assertNotSame('', $antwort->getParams()['fehler']);
		$this->assertSame([], $this->versand->adressen());
	}

	public function testOhneJedenTextGehtNichtsHinaus(): void {
		$antwort = $this->controller(felder: [
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis', 'textAngemeldete' => '', 'textWartende' => ' ',
		])->sende();

		$this->assertSame('benachrichtigung', $antwort->getTemplateName());
		$this->assertSame([], $this->versand->adressen());
		$this->assertNotSame('', $antwort->getParams()['fehler']);
	}

	/**
	 * Ein Betreff allein genuegt nicht: Mindestens einer der beiden Texte
	 * muss auch gefuellt sein. Bleibt nur die Warteliste leer, geht die
	 * Mail an die Angemeldeten trotzdem hinaus.
	 */
	public function testMitEinemLeerenUndEinemGefuelltenTextGehtDieMailHinaus(): void {
		$antwort = $this->sendeUndFolge([
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis',
			'textAngemeldete' => 'Text fuer die Angemeldeten', 'textWartende' => '',
		]);

		$this->assertSame('benachrichtigt', $antwort->getTemplateName());
		$this->assertSame(['erika.muster@example.org'], $this->versand->adressen());
	}

	public function testGescheiterteWerdenMitNamenGenannt(): void {
		$this->versand->laessScheiternBei('otto.warte@example.org');

		$daten = $this->sendeUndFolge([
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis',
			'textAngemeldete' => 'Text', 'textWartende' => 'Text',
		])->getParams();

		$this->assertSame(['Otto Warte'], $daten['gescheitert']);
		$this->assertSame('1 Nachricht ist hinausgegangen.', $daten['satz']);
	}

	/**
	 * Nach dem Versand kommt eine Umleitung, keine Seite. Laedt jemand die
	 * Ergebnisseite neu, schickt der Browser sonst das Formular noch einmal.
	 */
	public function testNachDemVersandLeitetDieAppUm(): void {
		$antwort = $this->controller(felder: [
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis', 'textAngemeldete' => 'Text',
		])->sende();

		$this->assertInstanceOf(RedirectResponse::class, $antwort);
		$this->assertSame(self::ADRESSE_ERGEBNIS, $antwort->getRedirectURL());
	}

	public function testDieErgebnisseiteNenntKursUndRueckweg(): void {
		$daten = $this->sendeUndFolge([
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis', 'textAngemeldete' => 'Text',
		])->getParams();

		$this->assertSame(self::KENNUNG, $daten['kennung']);
		$this->assertSame(19, $daten['id']);
	}

	/** Beim zweiten Aufruf ist das Ergebnis weg, und es geht keine Mail hinaus. */
	public function testNeuLadenDerErgebnisseiteFuehrtZurUebersicht(): void {
		$this->sendeUndFolge([
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis', 'textAngemeldete' => 'Text',
		]);

		$zweiterAufruf = $this->controller()->verschickt();

		$this->assertInstanceOf(RedirectResponse::class, $zweiterAufruf);
		$this->assertSame(self::ADRESSE_UEBERSICHT, $zweiterAufruf->getRedirectURL());
		$this->assertSame(['erika.muster@example.org'], $this->versand->adressen());
	}

	public function testOhneVorherigenVersandFuehrtDieErgebnisseiteZurUebersicht(): void {
		$antwort = $this->controller()->verschickt();

		$this->assertInstanceOf(RedirectResponse::class, $antwort);
		$this->assertSame(self::ADRESSE_UEBERSICHT, $antwort->getRedirectURL());
	}

	public function testOhneRechtZeigtDieErgebnisseiteNichts(): void {
		$this->controller(felder: [
			'kennung' => self::KENNUNG, 'betreff' => 'Hinweis', 'textAngemeldete' => 'Text',
		])->sende();

		$antwort = $this->controller(darfVerwalten: false)->verschickt();

		$this->assertInstanceOf(TemplateResponse::class, $antwort);
		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertArrayNotHasKey('gescheitert', $antwort->getParams());
	}
}
