<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\AnlegenController;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Fachlogik\Kursanlegen;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
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

final class AnlegenControllerTest extends TestCase {
	use MitTitelmuster;

	/** Die Angaben eines Kurses, den es im Bestand NICHT gibt. */
	private const GUELTIG = [
		'vorlage_anmeldung' => '23',
		'vorlage_warteliste' => '24',
		'von' => '2026-10-10',
		'bis' => '2026-10-11',
		'anmeldeschluss' => '2026-10-03',
		'plaetze' => '6',
	];

	/**
	 * Baut den Controller mit den Doppeln der vorigen Aufgaben.
	 *
	 * Die Werte gehen durch den IRequest-Stub, nicht als Parameter an die
	 * Methode: Genau so kommen sie spaeter auch an. Ein Test, der sie als
	 * Argumente uebergaebe, pruefte eine Methode, die es nicht gibt.
	 *
	 * @param array<string, string> $felder was im abgeschickten Formular stand
	 */
	private function controller(
		array $felder = [],
		bool $darfVerwalten = true,
		bool $sperreBelegt = false,
		bool $nurAnmeldevorlage = false,
		?FormulareDoppel $doppel = null,
		bool $uhrSpringt = false,
		array $betreiberwerte = [],
	): AnlegenController {
		$doppel ??= $nurAnmeldevorlage ? $this->nurAnmeldevorlage() : $this->bestand();

		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $feld, mixed $vorgabe = null): mixed => $felder[$feld] ?? $vorgabe);

		$recht = $this->createStub(Verwaltungsrecht::class);
		$recht->method('darfVerwalten')->willReturn($darfVerwalten);
		$recht->method('benutzer')->willReturn('anna');

		$lockingProvider = $this->createStub(ILockingProvider::class);
		if ($sperreBelegt) {
			$lockingProvider->method('acquireLock')
				->willThrowException(new LockedException('belegt'));
		}

		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('freigabeGruppe')->willReturn('Radfahrschule');
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');

		$timeFactory = $this->createStub(ITimeFactory::class);
		if ($uhrSpringt) {
			// Mitternacht faellt zwischen die Pruefung und die Erfolgsseite.
			// Danach liegt ein heute endender Kurs in der Vergangenheit.
			//
			// Die Zeiten stehen in UTC, der Controller dreht sie auf
			// Europe/Berlin: 22:00 UTC ist dort der 15.11. um 23:00, 23:30
			// UTC schon der 16.11. um 00:30. Wer hier UTC-Mitternacht nimmt,
			// laesst die Uhr eine Stunde zu frueh springen.
			$abgelesen = 0;
			$timeFactory->method('now')->willReturnCallback(
				static function () use (&$abgelesen): DateTimeImmutable {
					$abgelesen++;
					return new DateTimeImmutable(
						$abgelesen <= 1 ? '2026-11-15 22:00:00' : '2026-11-15 23:30:00',
						new DateTimeZone('UTC'));
				});
		} else {
			$timeFactory->method('now')->willReturn(
				new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC')));
		}

		$angaben = $this->betreiberangaben($betreiberwerte);
		$titelmuster = new Titelmuster($angaben);
		$zeitzone = new Zeitzone($angaben);
		$ablauf = new Ablauf($zeitzone);

		return new AnlegenController(
			'radfahrschule',
			$request,
			$doppel,
			new Kursanlegen($doppel, new ProtokollDoppel(),
				new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)), $zugangsdaten, $titelmuster, $ablauf),
			$recht,
			$zugangsdaten,
			$timeFactory,
			$this->createStub(INavigationManager::class), $titelmuster, $angaben, $ablauf, $zeitzone);
	}

	/**
	 * Der Bestand einer eingerichteten Instanz: zwei Vorlagen und ein
	 * laufender Kurs. Aufgebaut wie in KursanlegenTest - dort steht,
	 * warum.
	 */
	private function bestand(): FormulareDoppel {
		$bedingungstext = "- **Termin:** {{termin}}\n";
		return new FormulareDoppel([
			new Formular(id: 24, hash: 'editor0000000024',
				titel: 'VORLAGE Warteliste - Anfängerkurs', beschreibung: '',
				abgaben: 0, ablauf: 0,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', $bedingungstext)]),
			new Formular(id: 23, hash: 'editor0000000023',
				titel: 'VORLAGE Anmeldung - Anfängerkurs',
				beschreibung: 'https://cloud.example.org/apps/forms/s/PLATZHALTERWARTELISTE',
				abgaben: 0, ablauf: 0,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', $bedingungstext)]),
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 3, ablauf: 0),
		]);
	}

	private function nurAnmeldevorlage(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 23, hash: 'editor0000000023',
				titel: 'VORLAGE Anmeldung - Anfängerkurs', beschreibung: '',
				abgaben: 0, ablauf: 0),
		]);
	}

	/**
	 * @param array<string, string> $abweichend
	 * @return array<string, string>
	 */
	private function felder(array $abweichend = []): array {
		return array_merge(self::GUELTIG, $abweichend);
	}

	/**
	 * Wer nicht in der Gruppe steht, bekommt kein Formular. Die Uebersicht
	 * darf jeder sehen, der angemeldet ist - das Schreiben nicht.
	 */
	/**
	 * Der Weg zurueck von der Kontrollseite. Sie haengt die Werte an den
	 * Link; ohne sie fing jemand bei vier Feldern von vorn an.
	 */
	public function testDerWegZurueckBehaeltDieEingetipptenDaten(): void {
		$daten = $this->controller([
			'von' => '2026-12-05',
			'bis' => '2026-12-06',
			'anmeldeschluss' => '2026-11-28',
			'plaetze' => '12',
		])->formular()->getParams();

		$this->assertSame('2026-12-05', $daten['vonIso']);
		$this->assertSame('2026-12-06', $daten['bisIso']);
		$this->assertSame('2026-11-28', $daten['anmeldeschlussIso']);
		$this->assertSame('12', $daten['plaetze']);
	}

	/** Der erste Aufruf kommt von der Uebersicht und schickt nichts mit. */
	public function testOhneMitgeschickteWerteBleibtDasFormularLeer(): void {
		$daten = $this->controller([])->formular()->getParams();

		$this->assertSame('', $daten['vonIso']);
		$this->assertSame('', $daten['bisIso']);
		$this->assertSame('', $daten['anmeldeschlussIso']);
		$this->assertSame('6', $daten['plaetze']);
	}

	public function testOhneRechtGibtEsKeinFormular(): void {
		$antwort = $this->controller(darfVerwalten: false)->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Berechtigung', $antwort->getParams()['titel']);
	}

	public function testMitRechtStehenBeideAuswahlfelderBereit(): void {
		$antwort = $this->controller()->formular();

		$this->assertSame('anlegeformular', $antwort->getTemplateName());
		$this->assertNotSame([], $antwort->getParams()['vorlagenAnmeldung']);
		$this->assertNotSame([], $antwort->getParams()['vorlagenWarteliste']);
	}

	/**
	 * Ohne Vorlage laesst sich kein Kurs anlegen. Genannt wird nur, was
	 * fehlt - wer die vorhandene Art in der Meldung liest, sucht vergeblich
	 * nach ihr.
	 */
	public function testFehlendeVorlageWirdBenannt(): void {
		$antwort = $this->controller(nurAnmeldevorlage: true)->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString(
			'VORLAGE Warteliste', $antwort->getParams()['meldung']);
		$this->assertStringNotContainsString(
			'VORLAGE Anmeldung', $antwort->getParams()['meldung']);
	}

	public function testEineVertauschteVorlageWirdAbgewiesen(): void {
		// Im Feld fuer die Anmeldung steht die Wartelisten-Vorlage.
		$antwort = $this->controller($this->felder([
			'vorlage_anmeldung' => '24', 'vorlage_warteliste' => '23',
		]))->vorschau();

		$this->assertSame('meldung', $antwort->getTemplateName());
	}

	public function testEinUnlesbaresDatumWirdGemeldetUndNichtVerschluckt(): void {
		$antwort = $this->controller($this->felder(['von' => 'kein Datum']))->vorschau();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Kurstag', $antwort->getParams()['meldung']);
	}

	/**
	 * Ein Kalendertag, den es nicht gibt, muss auffallen.
	 *
	 * createFromFormat rollt still weiter, statt false zu liefern: Aus dem
	 * 31.06. wird der 01.07., aus "2026-13-45" der 14.02.2027. Die Pruefung
	 * auf === false sah davon nichts, und es entstuende ein Kurs zu einem
	 * Tag, den niemand eingegeben hat - zwei Formulare, Terminzeile und
	 * beide Ablaufwerte.
	 */
	public function testEinUnmoeglicherKalendertagWirdAbgewiesen(): void {
		$antwort = $this->controller($this->felder(['bis' => '2026-06-31']))->vorschau();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('2026-06-31', $antwort->getParams()['meldung']);
	}

	public function testDieVorschauZeigtBeideTitel(): void {
		$antwort = $this->controller($this->felder())->vorschau();

		$this->assertSame('vorschau', $antwort->getTemplateName());
		$this->assertStringContainsString(
			'10./11.10.2026', $antwort->getParams()['titelAnmeldung']);
	}

	/**
	 * Der Abgewiesene bekommt seine Angaben zurueck. Eine gewoehnliche
	 * Meldungsseite fuehrte nur zur Uebersicht, und von dort muesste alles
	 * neu getippt werden: Die App haelt die Eingaben nirgends.
	 */
	public function testEineAbsageGibtDieAngabenZurueck(): void {
		$antwort = $this->controller($this->felder(), sperreBelegt: true)->ausfuehren();

		$this->assertSame('beschaeftigt', $antwort->getTemplateName());
		$this->assertSame('2026-10-10', $antwort->getParams()['vonIso']);
		$this->assertSame(6, $antwort->getParams()['plaetze']);
	}

	/**
	 * "Gibt es schon" und "brach ab" sind zwei Fehler mit entgegengesetzter
	 * Folge. Nach einem Abbruch stehen halbe Formulare in Nextcloud; hier
	 * wurde nichts geschrieben. Dieselbe Ueberschrift schickte denselben
	 * Menschen vergeblich suchen.
	 */
	public function testEinVorhandenerKursBekommtEineEigeneUeberschrift(): void {
		$antwort = $this->controller($this->felder([
			'von' => '2026-09-12', 'bis' => '2026-09-13',
			'anmeldeschluss' => '2026-09-05',
		]))->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('gibt es schon', $antwort->getParams()['titel']);
	}

	/**
	 * Der Hinweis kommt aus den Einstellungen, nicht aus dem Template. Ein
	 * fester Satz nennte den Verein, dem diese App gerade gehoert - und der
	 * naechste Betreiber bekaeme ihn ungefragt.
	 */
	public function testDerHinweisDesBetreibersStehtAufDerErfolgsseite(): void {
		$antwort = $this->controller(
			$this->felder(),
			betreiberwerte: ['terminportal_hinweis' => 'Auch ins Terminportal eintragen.'],
		)->ausfuehren();

		$this->assertSame(
			'Auch ins Terminportal eintragen.',
			$antwort->getParams()['terminportalHinweis']);
	}

	/** Ohne Eintrag entfaellt der Absatz - nicht ein leerer Kasten. */
	public function testOhneHinweisBleibtDerAbsatzLeer(): void {
		$antwort = $this->controller($this->felder())->ausfuehren();

		$this->assertSame('', $antwort->getParams()['terminportalHinweis']);
	}

	public function testEinGelungenerLaufZeigtBeideLinks(): void {
		$antwort = $this->controller($this->felder())->ausfuehren();

		$this->assertSame('erfolg', $antwort->getTemplateName());
		$this->assertStringContainsString(
			'/apps/forms/s/', $antwort->getParams()['anmeldungLink']);
		$this->assertStringContainsString(
			'/apps/forms/s/', $antwort->getParams()['wartelisteLink']);
	}

	/**
	 * legeAn fragt Forms ein ZWEITES Mal, um die Kennung zu pruefen. Faellt
	 * Forms dazwischen aus, muss die Ausnahme gefangen werden - sonst
	 * bekommt der Bediener eine leere Fehlerseite.
	 */
	public function testEinAusfallZwischenDenAufrufenGibtEineMeldung(): void {
		$doppel = $this->bestand();
		$doppel->laessBeimZweitenMalScheitern('alleEigenen');

		$antwort = $this->controller(self::GUELTIG, doppel: $doppel)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Vorlagen nicht abrufbar', $antwort->getParams()['titel']);
	}

	/**
	 * Der Kurs steht, aber die Erfolgsseite laesst sich nicht mehr rechnen.
	 *
	 * Ohne diese Meldung gaebe es eine leere Fehlerseite - und die zwei
	 * oeffentlichen Links stehen NUR auf der Erfolgsseite. So erfaehrt der
	 * Bediener wenigstens, dass der Kurs da ist.
	 */
	public function testEinUhrensprungNachDemAnlegenGibtEineMeldung(): void {
		$antwort = $this->controller(
			array_merge(self::GUELTIG, [
				// Der Kurs endet an dem Tag, den die Uhr gerade verlaesst.
				'von' => '2026-11-15',
				'bis' => '2026-11-15',
				'anmeldeschluss' => '2026-11-08',
			]),
			uhrSpringt: true,
		)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Kurs angelegt, Anzeige unvollständig',
			$antwort->getParams()['titel']);
	}

	public function testOhneRechtWirdAuchNichtAngelegt(): void {
		$antwort = $this->controller($this->felder(), darfVerwalten: false)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Berechtigung', $antwort->getParams()['titel']);
	}
}
