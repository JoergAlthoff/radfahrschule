<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\VerschiebenController;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Fachlogik\Kursverschieben;
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

final class VerschiebenControllerTest extends TestCase {
	use MitTitelmuster;

	private const BEDINGUNGSTEXT = "- **Termin:** 12./13.09.2026\n";

	/** Die Angaben eines Termins, den es im Bestand NICHT gibt. */
	private const GUELTIG = [
		'kennung' => 'Anfängerkurs 12./13.09.2026',
		'von' => '2026-11-14',
		'bis' => '2026-11-15',
		'anmeldeschluss' => '2026-11-07',
	];

	private function bestand(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 1_000_000,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 2_000_000,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
		]);
	}

	/** @param array<string, string> $felder */
	private function controller(
		array $felder = [],
		bool $darfVerwalten = true,
		bool $sperreBelegt = false,
		?FormulareDoppel $doppel = null,
		array $betreiberwerte = [],
	): VerschiebenController {
		$doppel ??= $this->bestand();

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
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.example.org');

		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(
			new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC')));

		$angaben = $this->betreiberangaben($betreiberwerte);
		$titelmuster = new Titelmuster($angaben);
		$zeitzone = new Zeitzone($angaben);
		$ablauf = new Ablauf($zeitzone);

		return new VerschiebenController(
			'radfahrschule',
			$request,
			$doppel,
			new Kursverschieben($doppel, new ProtokollDoppel(),
				new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)), $zugangsdaten, $titelmuster, $ablauf),
			$recht,
			$timeFactory,
			$this->createStub(INavigationManager::class), $titelmuster, $angaben, $ablauf, $zeitzone);
	}

	/**
	 * @param array<string, string> $abweichend
	 * @return array<string, string>
	 */
	private function felder(array $abweichend = []): array {
		return array_merge(self::GUELTIG, $abweichend);
	}

	public function testOhneRechtGibtEsKeinFormular(): void {
		$antwort = $this->controller(['kennung' => 'Anfängerkurs 12./13.09.2026'], darfVerwalten: false)->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Berechtigung', $antwort->getParams()['titel']);
	}

	/**
	 * Das Formular ist mit dem BISHERIGEN Termin vorbelegt. Verschoben wird
	 * meist um wenige Tage; wer bei null anfangen muss, tippt das Jahr neu -
	 * und genau dort passiert der Fehler, den die Kontrollseite abfangen
	 * soll.
	 */
	public function testDasFormularIstMitDemAltenTerminVorbelegt(): void {
		$antwort = $this->controller(['kennung' => 'Anfängerkurs 12./13.09.2026'])->formular();

		$this->assertSame('verschiebeformular', $antwort->getTemplateName());
		$daten = $antwort->getParams();
		$this->assertSame('2026-09-13', $daten['bisIso']);
		$this->assertSame('Anfängerkurs 12./13.09.2026', $daten['kennung']);
	}

	/**
	 * Ein Kurs, dem das Anmeldeformular fehlt - nur die Warteliste steht da.
	 * Ohne die erste Haelfte des ODER in alterAnmeldeschluss griffe der Code
	 * auf null zu.
	 */
	public function testOhneAnmeldeformularBleibtDerAnmeldeschlussLeer(): void {
		$nurWarteliste = new FormulareDoppel([
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 2_000_000,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
		]);

		$antwort = $this->controller(
			['kennung' => 'Anfängerkurs 12./13.09.2026'],
			doppel: $nurWarteliste,
		)->formular();

		$this->assertSame('verschiebeformular', $antwort->getTemplateName());
		$this->assertSame('', $antwort->getParams()['anmeldeschlussIso']);
	}

	/**
	 * Traegt das erste Feld ebenfalls den LETZTEN Kurstag, wird aus
	 * "12./13.09." im Formular "13.09. bis 13.09." - und wer dann nur das
	 * Ende verschiebt, macht aus zwei Tagen eine Woche.
	 */
	public function testDasFormularZeigtDenErstenUndDenLetztenKurstag(): void {
		$daten = $this->controller(['kennung' => 'Anfängerkurs 12./13.09.2026'])
			->formular()->getParams();

		$this->assertSame('2026-09-12', $daten['vonIso']);
		$this->assertSame('2026-09-13', $daten['bisIso']);
	}

	/**
	 * verschiebe holt den Bestand INNERHALB der Sperre noch einmal. Dieselbe
	 * Luecke wie im AnlegenController - vorher eine leere Fehlerseite.
	 */
	public function testEinAusfallZwischenDenAufrufenGibtEineMeldung(): void {
		$doppel = $this->bestand();
		$doppel->laessBeimZweitenMalScheitern('alleEigenen');

		$antwort = $this->controller(self::GUELTIG, doppel: $doppel)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Kurs nicht abrufbar', $antwort->getParams()['titel']);
	}

	/**
	 * Scheitert es VOR dem ersten Schreibaufruf, ist in Nextcloud nichts
	 * geschehen. Die Ueberschrift "Das Verschieben brach ab" schickte
	 * jemanden nach Ueberresten suchen, die es nicht gibt - dafuer traegt
	 * die Ausnahme ihr Kennzeichen schonGeschrieben.
	 */
	public function testEinFehlerVorDemSchreibenNenntKeineUeberreste(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('formularHolen:19');

		$antwort = $this->controller(self::GUELTIG, doppel: $doppel)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Das Verschieben ging nicht', $antwort->getParams()['titel']);
		$this->assertStringContainsString('nichts geändert',
			$antwort->getParams()['meldung']);
	}

	/**
	 * Der Weg zurueck von der Kontrollseite.
	 *
	 * Sie schickt die eingetippten Daten schon mit - der Handler ignorierte
	 * sie und belegte wieder mit dem BISHERIGEN Termin vor. Wer sich
	 * vertippt hatte, fing bei drei Datumsfeldern von vorn an.
	 */
	public function testDerWegZurueckBehaeltDieEingetipptenDaten(): void {
		$daten = $this->controller([
			'kennung' => 'Anfängerkurs 12./13.09.2026',
			'von' => '2026-12-05',
			'bis' => '2026-12-06',
			'anmeldeschluss' => '2026-11-28',
		])->formular()->getParams();

		$this->assertSame('2026-12-05', $daten['vonIso']);
		$this->assertSame('2026-12-06', $daten['bisIso']);
		$this->assertSame('2026-11-28', $daten['anmeldeschlussIso']);
	}

	public function testEineUnbekannteKennungGibtEineMeldung(): void {
		$antwort = $this->controller(['kennung' => 'Gibt es nicht'])->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
	}

	/**
	 * Die Kennung kommt im POST-Koerper, nicht im Pfad: Sie traegt
	 * Schraegstriche, und ein Routen-Platzhalter matcht genau ein Segment.
	 */
	public function testOhneKennungGibtEsKeinFormular(): void {
		$antwort = $this->controller([])->formular();

		$this->assertSame('meldung', $antwort->getTemplateName());
	}

	/**
	 * Die Kontrollseite zeigt alt und neu NEBENEINANDER. Wer nur den neuen
	 * Stand sieht, kann nicht pruefen, ob er den richtigen Kurs erwischt hat.
	 */
	public function testDieKontrollseiteZeigtBeideStaende(): void {
		$daten = $this->controller($this->felder())->vorschau()->getParams();

		$this->assertSame('Anfängerkurs 12./13.09.2026', $daten['alteKennung']);
		$this->assertSame('Anfängerkurs 14./15.11.2026', $daten['neueKennung']);
	}

	/**
	 * Anzeige und verstecktes Feld brauchen getrennte Werte. Ein
	 * <input type="date"> verlangt ISO, ein Mensch liest 15.11.2026 - teilen
	 * sie sich ein Feld, gewinnt ISO, und die Seite zeigt 2026-11-15.
	 */
	public function testAnzeigeUndVerstecktesFeldSindGetrennt(): void {
		$daten = $this->controller($this->felder())->vorschau()->getParams();

		$this->assertSame('2026-11-15', $daten['bisIso']);
		$this->assertSame('15.11.2026', $daten['bisLesbar']);
	}

	/**
	 * Der bisherige Anmeldeschluss steht in der Spalte "bisher" - er ist da,
	 * im Ablaufzeitpunkt der Anmeldung. Ein Gedankenstrich behauptete, es
	 * gaebe ihn nicht, und im Browser fiel genau das auf.
	 */
	public function testDerAlteAnmeldeschlussStehtInDerKontrollseite(): void {
		$daten = $this->controller($this->felder())->vorschau()->getParams();

		// ablauf 1_000_000 ist der 12.01.1970 - der Wert zaehlt hier nicht,
		// nur dass ueberhaupt ein Datum dasteht.
		$this->assertMatchesRegularExpression(
			'/^\d{2}\.\d{2}\.\d{4}$/', $daten['alterAnmeldeschluss']);
	}

	public function testEinUnlesbaresDatumWirdGemeldet(): void {
		$antwort = $this->controller($this->felder(['von' => 'kein Datum']))->vorschau();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('Kurstag', $antwort->getParams()['meldung']);
	}

	/**
	 * Derselbe Ueberlauf wie beim Anlegen - hier trifft er einen Kurs, an
	 * dem schon Anmeldungen haengen.
	 */
	public function testEinUnmoeglicherKalendertagWirdAbgewiesen(): void {
		$antwort = $this->controller($this->felder(['bis' => '2026-11-31']))->vorschau();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertStringContainsString('2026-11-31', $antwort->getParams()['meldung']);
	}

	/** Wie auf der Erfolgsseite: Der Satz gehoert dem Betreiber. */
	public function testDerHinweisDesBetreibersStehtAufDerVerschobenSeite(): void {
		$antwort = $this->controller(
			$this->felder(),
			betreiberwerte: ['terminportal_hinweis' => 'Dort ebenfalls ändern.'],
		)->ausfuehren();

		$this->assertSame('Dort ebenfalls ändern.', $antwort->getParams()['terminportalHinweis']);
	}

	public function testOhneHinweisBleibtDerAbsatzLeer(): void {
		$antwort = $this->controller($this->felder())->ausfuehren();

		$this->assertSame('', $antwort->getParams()['terminportalHinweis']);
	}

	public function testEinVerschobenerKursWirdBestaetigt(): void {
		$antwort = $this->controller($this->felder())->ausfuehren();

		$this->assertSame('verschoben', $antwort->getTemplateName());
		$this->assertSame('Anfängerkurs 14./15.11.2026', $antwort->getParams()['neueKennung']);
	}

	/**
	 * Die Zahl der Angemeldeten reist mit, damit die Seite daran erinnern
	 * kann. Der Dienst schreibt ihnen NICHT - das braeche die Grenze, dass
	 * kein Byte Teilnehmerdaten Nextcloud verlaesst.
	 */
	/**
	 * Nach einem gelungenen Zurueckschreiben ist NICHTS aufzuraeumen - die
	 * Seite darf also nicht "Das Verschieben brach ab" heissen.
	 *
	 * scheitert() setzte schonGeschrieben fest auf true, und
	 * Kursverschieben setzte es danach erneut. Damit war der andere Zweig
	 * fuer jeden Schreibfehler tot: Die Ueberschrift schickte jemanden nach
	 * Ueberresten suchen, waehrend darunter stand "Der Kurs steht wieder auf
	 * dem alten Termin."
	 */
	public function testEinZurueckgerolltesVerschiebenSchicktNiemandenSuchen(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('frageAendern:19/30');

		$antwort = $this->controller($this->felder(), doppel: $doppel)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Das Verschieben ging nicht', $antwort->getParams()['titel']);
		$this->assertStringContainsString('alten Termin', $antwort->getParams()['meldung']);
	}

	/**
	 * Und die Aussage steht genau einmal da. Der Controller haengt seinen
	 * eigenen Satz nur an, wenn die Fachlogik keinen mitgibt.
	 */
	public function testDieEntwarnungStehtNurEinmal(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('frageAendern:19/30');

		$meldung = $this->controller($this->felder(), doppel: $doppel)
			->ausfuehren()->getParams()['meldung'];

		$this->assertSame(0, substr_count($meldung, 'In Nextcloud wurde nichts geändert'));
	}

	/**
	 * Bei einem LESEfehler gibt es keinen Zusatz aus der Fachlogik - dann
	 * muss der Controller die Entwarnung selbst geben.
	 */
	public function testEinLesefehlerGibtDieEntwarnung(): void {
		$doppel = $this->bestand();
		$doppel->laessScheitern('formularHolen:19');

		$meldung = $this->controller($this->felder(), doppel: $doppel)
			->ausfuehren()->getParams()['meldung'];

		$this->assertStringContainsString('In Nextcloud wurde nichts geändert', $meldung);
	}

	public function testDieErfolgsseiteKenntDieZahlDerAngemeldeten(): void {
		$daten = $this->controller($this->felder())->ausfuehren()->getParams();

		$this->assertSame(6, $daten['anmeldungen']);
	}

	public function testEineBelegteSperreGibtDieAngabenZurueck(): void {
		$antwort = $this->controller($this->felder(), sperreBelegt: true)->ausfuehren();

		$this->assertSame('verschiebevorschau', $antwort->getTemplateName());
		$this->assertNotSame('', $antwort->getParams()['hinweis']);
		$this->assertSame('2026-11-15', $antwort->getParams()['bisIso']);
	}

	public function testOhneRechtWirdNichtVerschoben(): void {
		$doppel = $this->bestand();
		$antwort = $this->controller($this->felder(), darfVerwalten: false,
			doppel: $doppel)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(19)->titel);
	}

	public function testOhneKennungPassiertNichts(): void {
		$antwort = $this->controller($this->felder(['kennung' => '']))->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
	}
}
