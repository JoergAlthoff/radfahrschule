<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\KursController;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\Frist;
use OCA\Radfahrschule\Fachlogik\Kursloeschen;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Freigabe;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCA\Radfahrschule\Sperre\Schreibsperre;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCA\Radfahrschule\Tests\Protokoll\ProtokollDoppel;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\INavigationManager;
use OCP\IRequest;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class KursControllerTest extends TestCase {
	use MitTitelmuster;

	/**
	 * Ein Kurs mit Link-Freigabe an der Anmeldung. Der Hash ist erfunden und
	 * 24 Zeichen lang - so lang ist ein Share-Hash; der Editor-Hash hat 16.
	 */
	private function bestand(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0,
				freigaben: [new Freigabe(1, Freigabe::TYP_LINK, 'aaaaaaaaaaaaaaaaaaaaaaaa')]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 0),
		]);
	}

	/** @param array<string, string> $felder */
	private function controller(
		?FormulareDoppel $doppel = null,
		bool $darfVerwalten = true,
		array $felder = [],
		bool $sperreFrei = true,
	): KursController {
		$doppel ??= $this->bestand();

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $feld, mixed $vorgabe = null): mixed => $felder[$feld] ?? $vorgabe);

		$recht = $this->createStub(Verwaltungsrecht::class);
		$recht->method('darfVerwalten')->willReturn($darfVerwalten);
		$recht->method('benutzer')->willReturn('anna');

		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');

		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(
			new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC')));

		$lockingProvider = $this->createStub(ILockingProvider::class);
		if (!$sperreFrei) {
			$lockingProvider->method('acquireLock')
				->willThrowException(new LockedException('belegt'));
		}

		return new KursController(
			'radfahrschule',
			$request,
			$doppel,
			new Kursloeschen($doppel, new ProtokollDoppel(),
				new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)), $zugangsdaten, $this->titelmuster()),
			$recht,
			$zugangsdaten,
			$timeFactory,
			$this->createStub(INavigationManager::class), $this->titelmuster(),
			new Frist($this->betreiberangaben()), $this->zeitzone());
	}

	public function testDieSeiteZeigtBeideFormulare(): void {
		$antwort = $this->controller()->zeige('19');

		$this->assertSame('kurs', $antwort->getTemplateName());
		$daten = $antwort->getParams();
		$this->assertSame('Anfängerkurs 12./13.09.2026', $daten['kennung']);
		$this->assertCount(2, $daten['zeilen']);
		$this->assertSame('Anmeldung', $daten['zeilen'][0]['beschriftung']);
		$this->assertSame(6, $daten['zeilen'][0]['abgaben']);
	}

	/**
	 * Der Link kommt aus den Freigaben und kostet je Formular einen Aufruf -
	 * die Liste liefert keine shares. Genau deshalb steht er hier und nicht
	 * in der Uebersicht.
	 */
	public function testDerOeffentlicheLinkStehtDabei(): void {
		$daten = $this->controller()->zeige('19')->getParams();

		$this->assertStringContainsString(
			'/apps/forms/s/aaaaaaaaaaaaaaaaaaaaaaaa', $daten['zeilen'][0]['link']);
		// Die Warteliste hat keine Link-Freigabe - dann steht dort nichts.
		$this->assertSame('', $daten['zeilen'][1]['link']);
	}

	/**
	 * Die Zeitform kommt aus der Uhr. Ein kommender Kurs darf sich nicht
	 * lesen, als sei er vorbei - auf derselben Seite, auf der der
	 * Loeschknopf steht.
	 */
	public function testDieSeiteNenntDenKurstagInDerRichtigenZeitform(): void {
		$daten = $this->controller()->zeige('19')->getParams();

		$this->assertStringContainsString('ist am 13.09.2026', $daten['kurstag']);
	}

	/**
	 * Eine Vorlagen-id findet nichts: Vorlagen sind gar nicht erst in der
	 * Kursliste. Das ist die Sperre, die verhindert, dass die Detailseite
	 * eine Vorlage zum Loeschen anbietet.
	 */
	public function testEineVorlageIstNichtErreichbar(): void {
		$doppel = new FormulareDoppel([
			new Formular(id: 23, hash: 'editor0000000023',
				titel: 'VORLAGE Anmeldung - Anfängerkurs',
				beschreibung: '', abgaben: 0, ablauf: 0),
		]);

		$antwort = $this->controller($doppel)->zeige('23');

		$this->assertSame('meldung', $antwort->getTemplateName());
	}

	public function testEineUnbekannteNummerGibtEineMeldung(): void {
		$antwort = $this->controller()->zeige('999');

		$this->assertSame('meldung', $antwort->getTemplateName());
	}

	/**
	 * Ein zweisegmentiger Pfad unter /kurs/ koennte auf eine andere Route
	 * gemuenzt sein. Welche gewinnt, ist je Instanz anders - der Controller
	 * prueft den Wert deshalb selbst.
	 */
	public function testEinNichtNumerischesSegmentGibtEineMeldung(): void {
		$antwort = $this->controller()->zeige('neu');

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Formularnummer', $antwort->getParams()['meldung']);
	}

	/**
	 * Ohne Recht gibt es die Kursseite gar nicht. Sie nennt den Kurstitel
	 * und die Zaehlerstaende - beides geht niemanden ausserhalb der Gruppe
	 * etwas an.
	 */
	public function testOhneRechtBleibtDieKursseiteVerschlossen(): void {
		$antwort = $this->controller(darfVerwalten: false)->zeige('19');

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertArrayNotHasKey('kennung', $antwort->getParams());
	}

	/**
	 * Loeschen nimmt seit 0.7.0 dieselbe Sperre wie Anlegen und Verschieben.
	 * Ohne sie zog ein Loeschender einem laufenden Verschieben die Formulare
	 * unter den Haenden weg.
	 */
	public function testBeiBelegterSperreWirdNichtGeloescht(): void {
		$doppel = $this->bestand();

		$antwort = $this->controller($doppel,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'],
			sperreFrei: false)->loesche();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Gerade beschäftigt', $antwort->getParams()['titel']);
		$this->assertSame([], $doppel->aufrufe);
	}

	public function testEinHalberKursWirdAufDerSeiteBenannt(): void {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0),
		]);

		$daten = $this->controller($doppel)->zeige('19')->getParams();

		$this->assertSame('Warteliste', $daten['fehlendeHaelfte']);
	}

	public function testOhneRechtWirdNichtGeloescht(): void {
		$doppel = $this->bestand();
		$antwort = $this->controller($doppel, darfVerwalten: false,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->loesche();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Berechtigung', $antwort->getParams()['titel']);
		$this->assertCount(2, $doppel->bestand());
	}

	public function testOhneKennungPassiertNichts(): void {
		$doppel = $this->bestand();
		$antwort = $this->controller($doppel, felder: [])->loesche();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertCount(2, $doppel->bestand());
	}

	public function testEinGeloeschterKursWirdBestaetigt(): void {
		$antwort = $this->controller(
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->loesche();

		$this->assertSame('geloescht', $antwort->getTemplateName());
		$this->assertSame('Anfängerkurs 12./13.09.2026', $antwort->getParams()['kennung']);
		$this->assertSame('', $antwort->getParams()['fehlend']);
	}

	/**
	 * Beim halben Kurs darf die Seite NICHT "samt Anmeldedaten entfernt"
	 * melden: Die andere Haelfte steht noch in Nextcloud, ihre Eintraege
	 * auch, und die Loeschfrist laeuft weiter.
	 */
	public function testBeimHalbenKursSagtDieMeldungWasStehenbleibt(): void {
		$halb = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0),
		]);

		$daten = $this->controller($halb,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->loesche()->getParams();

		$this->assertSame('Warteliste', $daten['fehlend']);
		$this->assertSame(['Anmeldung'], $daten['geloeschte']);
	}

	/**
	 * Geloescht wird JEDE Zeile des Kurses - genannt werden muessen also
	 * auch alle.
	 *
	 * Die Bestaetigung gab nur zeilen()[0] weiter. Bei einem doppelt
	 * angelegten Kurs verschwanden zwei Formulare samt Anmeldedaten, und
	 * genau die Seite, deren Aufgabe es ist, eine unwiderrufliche Loeschung
	 * festzuhalten, nannte eines.
	 */
	public function testDieBestaetigungNenntJedesGeloeschteFormular(): void {
		$doppelterKurs = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0),
			new Formular(id: 20, hash: 'editor0000000020',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 1, ablauf: 0),
		]);

		$daten = $this->controller($doppelterKurs,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->loesche()->getParams();

		$this->assertSame(['Anmeldung', 'Doppelt angelegt'], $daten['geloeschte']);
	}

	/**
	 * Ein abgebrochenes Loeschen nennt in eigenen Zeilen, was noch steht.
	 * Deshalb vorformatiert - in einem Absatz fraesse HTML die Umbrueche.
	 */
	public function testEinAbbruchWirdVorformatiertGezeigt(): void {
		$doppel = $this->bestand();
		$doppel->laessLoeschenScheitern(18);

		$antwort = $this->controller($doppel,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->loesche();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertTrue($antwort->getParams()['vorformatiert']);
		$this->assertStringContainsString('Das Löschen brach ab',
			$antwort->getParams()['titel']);
	}
	/**
	 * Die Nachfrage nennt jedes Formular mit seinem Zaehlerstand - und sie
	 * schreibt nichts. Ein Kurs verschwindet erst nach dem zweiten Klick.
	 */
	public function testDieNachfrageNenntWasWeggehtUndLoeschtNichts(): void {
		$doppel = $this->bestand();

		$antwort = $this->controller($doppel,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->nachfrage();

		$this->assertSame('loeschnachfrage', $antwort->getTemplateName());
		$daten = $antwort->getParams();
		$this->assertSame('Anfängerkurs 12./13.09.2026', $daten['kennung']);
		$this->assertSame(
			[['beschriftung' => 'Anmeldung', 'abgaben' => 6],
				['beschriftung' => 'Warteliste', 'abgaben' => 2]],
			$daten['zeilen']);
		$this->assertCount(2, $doppel->bestand());
		// Gelesen wird, geschrieben nicht.
		$this->assertSame(['alleEigenen'], $doppel->aufrufe);
	}

	/**
	 * Der Weg zurueck geht auf die Kursseite, und die haengt an einer
	 * Formularnummer - die Kennung passt in keinen Pfad.
	 */
	public function testDieNachfrageTraegtDenWegZurueck(): void {
		$daten = $this->controller(
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->nachfrage()->getParams();

		$this->assertSame(19, $daten['id']);
	}

	/**
	 * Fehlt eine Haelfte, bleibt sie beim Loeschen stehen. Wer hier zusagt,
	 * muss das vorher gelesen haben.
	 */
	public function testDieNachfrageWarntVorDerFehlendenHaelfte(): void {
		$halb = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 0),
		]);

		$daten = $this->controller($halb,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->nachfrage()->getParams();

		$this->assertSame('Warteliste', $daten['fehlendeHaelfte']);
	}

	public function testOhneRechtGibtEsKeineNachfrage(): void {
		$antwort = $this->controller(darfVerwalten: false,
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->nachfrage();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Berechtigung', $antwort->getParams()['titel']);
	}

	public function testOhneKennungGibtEsKeineNachfrage(): void {
		$antwort = $this->controller(felder: [])->nachfrage();

		$this->assertSame('meldung', $antwort->getTemplateName());
	}

	public function testEinUnbekannterKursWirdInDerNachfrageGemeldet(): void {
		$antwort = $this->controller(
			felder: ['kennung' => 'Anfängerkurs 01./02.01.2030'])->nachfrage();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Unbekannter Kurs', $antwort->getParams()['titel']);
	}
}
