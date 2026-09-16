<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\KursController;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\Benachrichtigen;
use OCA\Radfahrschule\Fachlogik\Frist;
use OCA\Radfahrschule\Fachlogik\Kursloeschen;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Formulare\Freigabe;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCA\Radfahrschule\Sperre\Schreibsperre;
use OCA\Radfahrschule\Tests\Benachrichtigung\VersandDoppel;
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

	private VersandDoppel $versand;

	protected function setUp(): void {
		$this->versand = new VersandDoppel();
	}

	/** Ein Kurs, dessen Formulare die Frage "email" tragen, mit je einem Eintrag. */
	private function bestandMitAdressen(): FormulareDoppel {
		$fragen = [new Frage(2, 'vorname', 'Vorname', ''), new Frage(3, 'nachname', 'Nachname', ''),
			new Frage(4, 'email', 'E-Mail', '')];
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 1, ablauf: 0, fragen: $fragen),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 1, ablauf: 0, fragen: $fragen),
		]);
		$doppel->setzeEmpfaenger(19, [new Empfaenger('Frau', 'Erika', 'Muster', 'erika.muster@example.org')]);
		$doppel->setzeEmpfaenger(18, [new Empfaenger('', 'Otto', 'Warte', 'otto.warte@example.org')]);
		return $doppel;
	}

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

	/**
	 * @param array<string, string> $felder
	 * @param array<string, string> $einstellungen
	 */
	private function controller(
		?FormulareDoppel $doppel = null,
		bool $darfVerwalten = true,
		array $felder = [],
		bool $sperreFrei = true,
		array $einstellungen = [],
		bool $versandAbgeschaltet = false,
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

		$versand = $versandAbgeschaltet ? new VersandDoppel(abgeschaltet: true) : $this->versand;
		$betreiberangaben = $this->betreiberangaben($einstellungen);
		$benachrichtigen = new Benachrichtigen($doppel, $versand, new ProtokollDoppel(), $betreiberangaben);

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
			new Frist($this->betreiberangaben()), $this->zeitzone(),
			$benachrichtigen, $betreiberangaben);
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
		// Gelesen wird vor der Sperre, geschrieben erst danach.
		$schreibende = array_filter($doppel->aufrufe,
			static fn (string $aufruf): bool => !str_starts_with($aufruf, 'alleEigenen')
				&& !str_starts_with($aufruf, 'formularHolen:'));
		$this->assertSame([], array_values($schreibende));
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
		$schreibende = array_filter($doppel->aufrufe,
			static fn (string $aufruf): bool => !str_starts_with($aufruf, 'alleEigenen')
				&& !str_starts_with($aufruf, 'formularHolen:'));
		$this->assertSame([], array_values($schreibende));
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

	public function testDieNachfrageBelegtDieAbsageAusDenEinstellungenVor(): void {
		$daten = $this->controller($this->bestandMitAdressen(),
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'],
			einstellungen: [
				'absage_betreff' => 'Der {kursart} fällt aus',
				'absage_text_angemeldete' => 'An Angemeldete',
				'absage_text_wartende' => 'An Wartende',
			])->nachfrage()->getParams();

		$this->assertSame('Der {kursart} fällt aus', $daten['betreff']);
		$this->assertSame('An Angemeldete', $daten['textAngemeldete']);
		$this->assertSame('An Wartende', $daten['textWartende']);
		$this->assertSame('', $daten['grundOhneAbsage']);
		$this->assertSame('', $daten['fehler']);
	}

	/** Die Nachfrage liest keine Adresse. Das geschieht erst beim Loeschen. */
	public function testDieNachfrageLiestKeineAdresse(): void {
		$doppel = $this->bestandMitAdressen();

		$this->controller($doppel, felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->nachfrage();

		$gelesen = array_filter($doppel->aufrufe,
			static fn (string $aufruf): bool => str_starts_with($aufruf, 'empfaenger:'));
		$this->assertSame([], array_values($gelesen));
	}

	/** Ohne Frage "email" laesst sich loeschen, nur ohne Absage. Die Seite sagt es vorher. */
	public function testOhneEmailFrageNenntDieNachfrageDenGrund(): void {
		$antwort = $this->controller(
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'])->nachfrage();

		$this->assertSame('loeschnachfrage', $antwort->getTemplateName());
		$this->assertStringContainsString('„email"', $antwort->getParams()['grundOhneAbsage']);
		$this->assertStringContainsString('trotzdem löschen', $antwort->getParams()['grundOhneAbsage']);
	}

	public function testBeiAbgeschaltetemVersandNenntDieNachfrageDenGrund(): void {
		$daten = $this->controller($this->bestandMitAdressen(),
			felder: ['kennung' => 'Anfängerkurs 12./13.09.2026'],
			versandAbgeschaltet: true)->nachfrage()->getParams();

		$this->assertStringContainsString('Mailversand abgeschaltet', $daten['grundOhneAbsage']);
	}

	/** @return array<string, string> die Felder einer Absage an beide Listen */
	private function absagefelder(): array {
		return [
			'kennung' => 'Anfängerkurs 12./13.09.2026',
			'betreff' => 'Der {kursart} am {termin} fällt aus',
			'textAngemeldete' => 'Guten Tag {anrede} {nachname}',
			'textWartende' => 'Hallo {vorname}',
		];
	}

	public function testNachDemLoeschenGehtDieAbsageAnBeideListen(): void {
		$doppel = $this->bestandMitAdressen();

		$antwort = $this->controller($doppel, felder: $this->absagefelder())->loesche();

		$this->assertSame('geloescht', $antwort->getTemplateName());
		$this->assertSame([], $doppel->bestand());
		$this->assertSame('2 Nachrichten sind hinausgegangen.', $antwort->getParams()['absage']);
		$this->assertSame('Der Anfängerkurs am 12./13.09.2026 fällt aus',
			$this->versand->nachrichtAn('erika.muster@example.org')?->betreff);
		$this->assertSame('Guten Tag Frau Muster', $this->versand->nachrichtAn('erika.muster@example.org')?->text);
		$this->assertSame('Hallo Otto', $this->versand->nachrichtAn('otto.warte@example.org')?->text);
	}

	/** Scheitert das Loeschen, geht keine Absage hinaus, auch nicht an eine Liste, deren Formular schon weg ist. */
	public function testScheitertDasLoeschenGehtKeineAbsageHinaus(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessLoeschenScheitern(18);

		$antwort = $this->controller($doppel, felder: $this->absagefelder())->loesche();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame([], $this->versand->adressen());
	}

	/**
	 * War eine Absage gewollt, sagt die Abbruchmeldung, dass keine hinausging
	 * und dass sich Adressen aus einem schon geloeschten Formular nicht mehr
	 * holen lassen.
	 */
	public function testEinAbbruchMitGewollterAbsageNenntDieFehlendeAbsage(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessLoeschenScheitern(18);

		$antwort = $this->controller($doppel, felder: $this->absagefelder())->loesche();

		$meldung = $antwort->getParams()['meldung'];
		$this->assertSame(1, substr_count($meldung, 'Es ist keine Absage hinausgegangen.'));
		$this->assertSame([], $this->versand->adressen());
	}

	/** Ohne gewollte Absage bleibt die Abbruchmeldung, wie sie war. */
	public function testEinAbbruchOhneGewollteAbsageNenntKeineFehlendeAbsage(): void {
		$doppel = $this->bestand();
		$doppel->laessLoeschenScheitern(18);

		$antwort = $this->controller($doppel, felder: [
			'kennung' => 'Anfängerkurs 12./13.09.2026',
			'betreff' => 'Bleibt ungenutzt',
			'textAngemeldete' => '',
			'textWartende' => '',
		])->loesche();

		$meldung = $antwort->getParams()['meldung'];
		$this->assertStringNotContainsString('Es ist keine Absage hinausgegangen.', $meldung);
	}

	/** Nach dem Loeschen gibt es die Adressen nicht mehr. */
	public function testDieAdressenWerdenVorDemLoeschenGelesen(): void {
		$doppel = $this->bestandMitAdressen();

		$this->controller($doppel, felder: $this->absagefelder())->loesche();

		$gelesen = array_search('empfaenger:19', $doppel->aufrufe, true);
		$geloescht = array_search('formularLoeschen:19', $doppel->aufrufe, true);
		$this->assertIsInt($gelesen);
		$this->assertIsInt($geloescht);
		$this->assertLessThan($geloescht, $gelesen);
	}

	public function testScheitertDasLesenDerAdressenWirdNichtsGeloescht(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessScheitern('empfaenger:18');

		$antwort = $this->controller($doppel, felder: $this->absagefelder())->loesche();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Kurs nicht abrufbar', $antwort->getParams()['titel']);
		$this->assertCount(2, $doppel->bestand());
		$this->assertSame([], $this->versand->adressen());
	}

	/** Ein Text ohne Betreff loescht nichts, und das Getippte bleibt stehen. */
	public function testOhneBetreffWirdNichtsGeloescht(): void {
		$doppel = $this->bestandMitAdressen();
		$felder = $this->absagefelder();
		$felder['betreff'] = '';

		$antwort = $this->controller($doppel, felder: $felder)->loesche();

		$this->assertSame('loeschnachfrage', $antwort->getTemplateName());
		$this->assertNotSame('', $antwort->getParams()['fehler']);
		$this->assertSame('Guten Tag {anrede} {nachname}', $antwort->getParams()['textAngemeldete']);
		$this->assertCount(2, $doppel->bestand());
	}

	public function testMitLeerenTextenWirdOhneAbsageGeloescht(): void {
		$doppel = $this->bestandMitAdressen();

		$antwort = $this->controller($doppel, felder: [
			'kennung' => 'Anfängerkurs 12./13.09.2026',
			'betreff' => 'Bleibt ungenutzt',
			'textAngemeldete' => '',
			'textWartende' => '',
		])->loesche();

		$this->assertSame('geloescht', $antwort->getTemplateName());
		$this->assertSame([], $doppel->bestand());
		$this->assertSame('', $antwort->getParams()['absage']);
		$this->assertSame([], $this->versand->adressen());
	}

	/** Ein leerer Text schliesst nur seine Liste aus, nicht die ganze Absage. */
	public function testMitNurEinemGefuelltenTextGehtDieAbsageAnDieseListe(): void {
		$doppel = $this->bestandMitAdressen();

		$antwort = $this->controller($doppel, felder: [
			'kennung' => 'Anfängerkurs 12./13.09.2026',
			'betreff' => 'Der {kursart} am {termin} fällt aus',
			'textAngemeldete' => 'Guten Tag {anrede} {nachname}',
			'textWartende' => '',
		])->loesche();

		$this->assertSame('geloescht', $antwort->getTemplateName());
		$this->assertSame([], $doppel->bestand());
		$this->assertSame('1 Nachricht ist hinausgegangen.', $antwort->getParams()['absage']);
		$this->assertSame('Guten Tag Frau Muster',
			$this->versand->nachrichtAn('erika.muster@example.org')?->text);
		$this->assertNull($this->versand->nachrichtAn('otto.warte@example.org'));
	}

	/** Ohne Frage "email" wird geloescht, nur ohne Absage. */
	public function testOhneEmailFrageWirdOhneAbsageGeloescht(): void {
		$doppel = $this->bestand();

		$antwort = $this->controller($doppel, felder: $this->absagefelder())->loesche();

		$this->assertSame('geloescht', $antwort->getTemplateName());
		$this->assertSame([], $doppel->bestand());
		$this->assertSame('', $antwort->getParams()['absage']);
	}

	/**
	 * Ein zweiter Absendeversuch nach dem Loeschen findet den Kurs nicht mehr
	 * und schickt darum keine zweite Absage. Eine Gegenprobe ist hier nicht
	 * sinnvoll herzustellen, ohne Kursloeschen umzubauen: Der Test haelt
	 * bereits fest, dass die KENNUNG nach dem Loeschen nicht mehr gefunden
	 * wird - genau das verhindert den zweiten Versand.
	 */
	public function testEinZweiterAbsendenNachDemLoeschenSchicktKeineZweiteAbsage(): void {
		$doppel = $this->bestandMitAdressen();

		$ersteAntwort = $this->controller($doppel, felder: $this->absagefelder())->loesche();
		$zweiteAntwort = $this->controller($doppel, felder: $this->absagefelder())->loesche();

		$this->assertSame('geloescht', $ersteAntwort->getTemplateName());
		$this->assertNotSame('geloescht', $zweiteAntwort->getTemplateName());
		$this->assertCount(2, $this->versand->adressen());
	}

	public function testGescheiterteAbsagenWerdenMitNamenGenannt(): void {
		$this->versand->laessScheiternBei('erika.muster@example.org');

		$daten = $this->controller($this->bestandMitAdressen(),
			felder: $this->absagefelder())->loesche()->getParams();

		$this->assertSame('1 Nachricht ist hinausgegangen.', $daten['absage']);
		$this->assertSame(['Erika Muster'], $daten['gescheitert']);
	}
}
