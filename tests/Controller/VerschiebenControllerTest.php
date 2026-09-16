<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\VerschiebenController;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Fachlogik\Benachrichtigen;
use OCA\Radfahrschule\Fachlogik\Kursverschieben;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Protokoll\Vorgang;
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

	private VersandDoppel $versand;

	private ProtokollDoppel $protokoll;

	protected function setUp(): void {
		$this->versand = new VersandDoppel();
		$this->protokoll = new ProtokollDoppel();
	}

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

	/** Wie bestand(), aber die Anmeldung hat noch niemand ausgefuellt. */
	private function bestandOhneAnmeldungen(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 0, ablauf: 1_000_000,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 2_000_000,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT)]),
		]);
	}

	/** Wie bestand(), dazu Fragen fuer die Anrede und je ein Eintrag mit Adresse. */
	private function bestandMitAdressen(): FormulareDoppel {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 1, ablauf: 1_000_000,
				fragen: [
					new Frage(30, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT),
					new Frage(31, 'vorname', 'Vorname', ''),
					new Frage(32, 'nachname', 'Nachname', ''),
					new Frage(33, 'email', 'E-Mail', ''),
				]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 1, ablauf: 2_000_000,
				fragen: [
					new Frage(40, 'teilnahmebedingungen', 'B', self::BEDINGUNGSTEXT),
					new Frage(41, 'vorname', 'Vorname', ''),
					new Frage(42, 'nachname', 'Nachname', ''),
					new Frage(43, 'email', 'E-Mail', ''),
				]),
		]);
		$doppel->setzeEmpfaenger(19, [new Empfaenger('Frau', 'Erika', 'Muster', 'erika.muster@example.org')]);
		$doppel->setzeEmpfaenger(18, [new Empfaenger('', 'Otto', 'Warte', 'otto.warte@example.org')]);
		return $doppel;
	}

	/** @param array<string, string> $felder */
	private function controller(
		array $felder = [],
		bool $darfVerwalten = true,
		bool $sperreBelegt = false,
		?FormulareDoppel $doppel = null,
		array $betreiberwerte = [],
		bool $versandAbgeschaltet = false,
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

		$versand = $versandAbgeschaltet ? new VersandDoppel(abgeschaltet: true) : $this->versand;
		$benachrichtigen = new Benachrichtigen($doppel, $versand, $this->protokoll, $angaben);

		return new VerschiebenController(
			'radfahrschule',
			$request,
			$doppel,
			new Kursverschieben($doppel, $this->protokoll,
				new Schreibsperre($lockingProvider, $this->createStub(LoggerInterface::class)), $zugangsdaten, $titelmuster, $ablauf),
			$recht,
			$timeFactory,
			$this->createStub(INavigationManager::class), $titelmuster, $angaben, $ablauf, $zeitzone,
			$benachrichtigen);
	}

	/**
	 * @param array<string, string> $abweichend
	 * @return array<string, string>
	 */
	private function felder(array $abweichend = []): array {
		return array_merge(self::GUELTIG, $abweichend);
	}

	/** @return array<string, string> die drei Texte der Verschiebung in den Einstellungen */
	private function vorbelegung(): array {
		return [
			'verschiebung_betreff' => 'Der {kursart} ist verschoben',
			'verschiebung_text_angemeldete' => 'An Angemeldete',
			'verschiebung_text_wartende' => 'An Wartende',
		];
	}

	public function testDieKontrollseiteBelegtDieNachrichtAusDenEinstellungenVor(): void {
		$daten = $this->controller($this->felder(), doppel: $this->bestandMitAdressen(),
			betreiberwerte: $this->vorbelegung())->vorschau()->getParams();

		$this->assertSame('Der {kursart} ist verschoben', $daten['betreff']);
		$this->assertSame('An Angemeldete', $daten['textAngemeldete']);
		$this->assertSame('An Wartende', $daten['textWartende']);
		$this->assertSame('', $daten['grundOhneNachricht']);
		$this->assertSame('', $daten['fehler']);
	}

	/** Auf dem Weg zurueck und wieder vor bleibt das Getippte, auch ein geleertes Feld. */
	public function testDieKontrollseiteNimmtDasMitgebrachteStattDerVorbelegung(): void {
		$daten = $this->controller($this->felder([
			'betreff' => 'Getippt',
			'textAngemeldete' => "Zeile 1\nZeile 2",
			'textWartende' => '',
		]), doppel: $this->bestandMitAdressen(), betreiberwerte: $this->vorbelegung())
			->vorschau()->getParams();

		$this->assertSame('Getippt', $daten['betreff']);
		$this->assertSame("Zeile 1\nZeile 2", $daten['textAngemeldete']);
		$this->assertSame('', $daten['textWartende']);
	}

	/** @return array<string, string> derselbe Termin, nur der Anmeldeschluss aendert sich */
	private function felderNurFristGeaendert(array $abweichend = []): array {
		return $this->felder(array_merge([
			'von' => '2026-09-12',
			'bis' => '2026-09-13',
			'anmeldeschluss' => '2026-09-01',
		], $abweichend));
	}

	/**
	 * Bleibt der Termin gleich, waere die Nachricht "ist verschoben" falsch.
	 * Die Kontrollseite belegt Betreff und Texte dann nicht vor.
	 */
	public function testBeiReinerFristaenderungBleibtDieNachrichtLeer(): void {
		$daten = $this->controller($this->felderNurFristGeaendert(),
			betreiberwerte: $this->vorbelegung())->vorschau()->getParams();

		$this->assertSame('', $daten['betreff']);
		$this->assertSame('', $daten['textAngemeldete']);
		$this->assertSame('', $daten['textWartende']);
		$this->assertTrue($daten['nurFristGeaendert']);
	}

	/** Kommt trotzdem etwas Getipptes mit, bleibt es stehen. */
	public function testBeiReinerFristaenderungBleibtDasMitgebrachteStehen(): void {
		$daten = $this->controller($this->felderNurFristGeaendert([
			'betreff' => 'Getippt',
			'textAngemeldete' => 'Text A',
			'textWartende' => 'Text W',
		]))->vorschau()->getParams();

		$this->assertSame('Getippt', $daten['betreff']);
		$this->assertSame('Text A', $daten['textAngemeldete']);
		$this->assertSame('Text W', $daten['textWartende']);
		$this->assertFalse($daten['nurFristGeaendert']);
	}

	public function testDasFormularReichtDasGetippteWeiter(): void {
		$daten = $this->controller([
			'kennung' => 'Anfängerkurs 12./13.09.2026',
			'betreff' => 'Getippt',
			'textAngemeldete' => 'An Angemeldete',
			'textWartende' => '',
		])->formular()->getParams();

		$this->assertSame(
			['betreff' => 'Getippt', 'textAngemeldete' => 'An Angemeldete', 'textWartende' => ''],
			$daten['mitgebracht']);
	}

	/** Von der Kursseite kommt nichts mit. Dann gilt spaeter die Vorbelegung. */
	public function testVonDerKursseiteBringtDasFormularNichtsMit(): void {
		$daten = $this->controller(['kennung' => 'Anfängerkurs 12./13.09.2026'])
			->formular()->getParams();

		$this->assertNull($daten['mitgebracht']);
	}

	/** Die Kontrollseite liest keine Adresse. Das geschieht erst beim Verschieben. */
	public function testDieKontrollseiteLiestKeineAdresse(): void {
		$doppel = $this->bestandMitAdressen();

		$this->controller($this->felder(), doppel: $doppel)->vorschau();

		$gelesen = array_filter($doppel->aufrufe,
			static fn (string $aufruf): bool => str_starts_with($aufruf, 'empfaenger:'));
		$this->assertSame([], array_values($gelesen));
	}

	/** Ohne Frage "email" laesst sich verschieben, nur ohne Nachricht. Die Seite sagt es vorher. */
	public function testOhneEmailFrageNenntDieKontrollseiteDenGrund(): void {
		$daten = $this->controller($this->felder())->vorschau()->getParams();

		$this->assertStringContainsString('„email"', $daten['grundOhneNachricht']);
		$this->assertStringContainsString('trotzdem verschieben', $daten['grundOhneNachricht']);
	}

	public function testBeiAbgeschaltetemVersandNenntDieKontrollseiteDenGrund(): void {
		$daten = $this->controller($this->felder(), doppel: $this->bestandMitAdressen(),
			versandAbgeschaltet: true)->vorschau()->getParams();

		$this->assertStringContainsString('Mailversand abgeschaltet', $daten['grundOhneNachricht']);
	}

	public function testAntwortetFormsBeimPruefenGibtEsEineMeldung(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessScheitern('formularHolen:19');

		$antwort = $this->controller($this->felder(), doppel: $doppel)->vorschau();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Kurs nicht abrufbar', $antwort->getParams()['titel']);
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

	/** Die Zahl reist mit. Ging keine Nachricht hinaus, erinnert die Seite an die Angemeldeten. */
	public function testDieErfolgsseiteKenntDieZahlDerAngemeldeten(): void {
		$daten = $this->controller($this->felder())->ausfuehren()->getParams();

		$this->assertSame(6, $daten['anmeldungen']);
	}

	/** Ohne jede Nachricht wissen die Angemeldeten nichts vom neuen Termin. */
	public function testOhneNachrichtErinnertDieSeiteAnDieAngemeldeten(): void {
		$antwort = $this->controller($this->felder(), doppel: $this->bestandMitAdressen())->ausfuehren();

		$this->assertTrue($antwort->getParams()['erinnerung']);
	}

	/**
	 * Steht nur ein Text an die Warteliste, bekamen die Angemeldeten
	 * trotzdem keine Nachricht - die Erinnerung darf nicht am Block "Nachricht
	 * zum neuen Termin" haengen, den es dann ja gibt.
	 */
	public function testNurTextAnDieWartelisteErinnertTrotzdemAnDieAngemeldeten(): void {
		$antwort = $this->controller($this->felder([
			'betreff' => 'Verschoben', 'textAngemeldete' => '', 'textWartende' => 'Neu: {neuer_termin}',
		]), doppel: $this->bestandMitAdressen())->ausfuehren();

		$this->assertTrue($antwort->getParams()['erinnerung']);
	}

	/** Bekamen die Angemeldeten eine Nachricht, erinnert die Seite nicht mehr an sie. */
	public function testMitTextAnDieAngemeldetenErinnertDieSeiteNicht(): void {
		$antwort = $this->controller($this->nachrichtfelder(), doppel: $this->bestandMitAdressen())->ausfuehren();

		$this->assertFalse($antwort->getParams()['erinnerung']);
	}

	/** Ohne Anmeldungen gibt es niemanden, an den die Seite erinnern muesste. */
	public function testOhneAnmeldungenErinnertDieSeiteNicht(): void {
		$antwort = $this->controller($this->felder(), doppel: $this->bestandOhneAnmeldungen())->ausfuehren();

		$this->assertFalse($antwort->getParams()['erinnerung']);
	}

	/** @return array<string, string> neuer Termin und eine Nachricht an beide Listen */
	private function nachrichtfelder(): array {
		return $this->felder([
			'betreff' => 'Der {kursart} am {termin} ist verschoben',
			'textAngemeldete' => 'Guten Tag {anrede} {nachname}, neu: {neuer_termin}',
			'textWartende' => 'Hallo {vorname}, neu: {neuer_termin}',
		]);
	}

	public function testNachDemVerschiebenGehtDieNachrichtAnBeideListen(): void {
		$doppel = $this->bestandMitAdressen();

		$antwort = $this->controller($this->nachrichtfelder(), doppel: $doppel)->ausfuehren();

		$this->assertSame('verschoben', $antwort->getTemplateName());
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 14./15.11.2026',
			$doppel->formularHolen(19)->titel);
		$this->assertSame('2 Nachrichten sind hinausgegangen.', $antwort->getParams()['nachricht']);
		$this->assertSame('Der Anfängerkurs am 12./13.09.2026 ist verschoben',
			$this->versand->nachrichtAn('erika.muster@example.org')?->betreff);
		$this->assertSame('Guten Tag Frau Muster, neu: 14./15.11.2026',
			$this->versand->nachrichtAn('erika.muster@example.org')?->text);
		$this->assertSame('Hallo Otto, neu: 14./15.11.2026',
			$this->versand->nachrichtAn('otto.warte@example.org')?->text);
	}

	/**
	 * Eine Nachricht zu einem Termin, der nicht gilt, waere falsch. Der Kurs
	 * steht nach dem Zurueckschreiben am alten Termin.
	 */
	public function testScheitertDasVerschiebenGehtKeineNachrichtHinaus(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessScheitern('frageAendern:19/30');

		$antwort = $this->controller($this->nachrichtfelder(), doppel: $doppel)->ausfuehren();

		$this->assertSame('Das Verschieben ging nicht', $antwort->getParams()['titel']);
		$this->assertSame([], $this->versand->adressen());
		$this->assertSame(1, substr_count($antwort->getParams()['meldung'],
			'Es ist keine Nachricht zum neuen Termin hinausgegangen.'));
	}

	/** Bricht es ab und bleibt ein Teil stehen, sagt die Meldung es ebenso. */
	public function testBrichtDasVerschiebenAbNenntDieMeldungDieFehlendeNachricht(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessScheitern('frageAendern:19/30');
		$doppel->laessBeimZweitenMalScheitern('formularAendern:19');

		$antwort = $this->controller($this->nachrichtfelder(), doppel: $doppel)->ausfuehren();

		$this->assertSame('Das Verschieben brach ab', $antwort->getParams()['titel']);
		$this->assertSame([], $this->versand->adressen());
		$this->assertSame(1, substr_count($antwort->getParams()['meldung'],
			'Es ist keine Nachricht zum neuen Termin hinausgegangen.'));
	}

	public function testOhneGewollteNachrichtSagtDieMeldungNichtsDazu(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessScheitern('frageAendern:19/30');

		$antwort = $this->controller($this->felder(), doppel: $doppel)->ausfuehren();

		$this->assertStringNotContainsString('keine Nachricht', $antwort->getParams()['meldung']);
	}

	public function testMitLeerenTextenWirdOhneNachrichtVerschoben(): void {
		$doppel = $this->bestandMitAdressen();

		$antwort = $this->controller($this->felder([
			'betreff' => 'Egal', 'textAngemeldete' => '', 'textWartende' => '',
		]), doppel: $doppel)->ausfuehren();

		$this->assertSame('verschoben', $antwort->getTemplateName());
		$this->assertSame('', $antwort->getParams()['nachricht']);
		$this->assertSame([], $this->versand->adressen());
	}

	/** Mit nur einem Text geht die Nachricht nur an diese Liste. */
	public function testMitNurEinemTextGehtDieNachrichtAnDieseListe(): void {
		$antwort = $this->controller($this->felder([
			'betreff' => 'Verschoben', 'textAngemeldete' => 'Neu: {neuer_termin}', 'textWartende' => '',
		]), doppel: $this->bestandMitAdressen())->ausfuehren();

		$this->assertSame('1 Nachricht ist hinausgegangen.', $antwort->getParams()['nachricht']);
		$this->assertNull($this->versand->nachrichtAn('otto.warte@example.org'));
	}

	/** Ein Text ohne Betreff verschiebt nichts, und das Getippte bleibt stehen. */
	public function testOhneBetreffWirdNichtsVerschoben(): void {
		$doppel = $this->bestandMitAdressen();

		$antwort = $this->controller(
			array_merge($this->nachrichtfelder(), ['betreff' => '']), doppel: $doppel)->ausfuehren();

		$this->assertSame('verschiebevorschau', $antwort->getTemplateName());
		$this->assertStringContainsString('Betreff', $antwort->getParams()['fehler']);
		$this->assertSame('Hallo {vorname}, neu: {neuer_termin}', $antwort->getParams()['textWartende']);
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(19)->titel);
	}

	public function testScheitertDasLesenDerAdressenWirdNichtsVerschoben(): void {
		$doppel = $this->bestandMitAdressen();
		$doppel->laessScheitern('empfaenger:18');

		$antwort = $this->controller($this->nachrichtfelder(), doppel: $doppel)->ausfuehren();

		$this->assertSame('Kurs nicht abrufbar', $antwort->getParams()['titel']);
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(19)->titel);
		$this->assertSame([], $this->versand->adressen());
	}

	public function testDieAdressenWerdenVorDemVerschiebenGelesen(): void {
		$doppel = $this->bestandMitAdressen();

		$this->controller($this->nachrichtfelder(), doppel: $doppel)->ausfuehren();

		$gelesen = array_search('empfaenger:19', $doppel->aufrufe, true);
		$geaendert = array_search('formularAendern:19', $doppel->aufrufe, true);
		$this->assertIsInt($gelesen);
		$this->assertIsInt($geaendert);
		$this->assertLessThan($geaendert, $gelesen);
	}

	/** Ohne Frage "email" wird verschoben, nur ohne Nachricht. */
	public function testOhneEmailFrageWirdOhneNachrichtVerschoben(): void {
		$antwort = $this->controller($this->nachrichtfelder())->ausfuehren();

		$this->assertSame('verschoben', $antwort->getTemplateName());
		$this->assertSame('', $antwort->getParams()['nachricht']);
		$this->assertSame([], $this->versand->adressen());
	}

	/**
	 * Ohne Text braucht das Verschieben keinen Aufruf mehr als ohne
	 * Nachricht: grundOhneNachricht() liest das Anmeldeformular zusaetzlich.
	 * Erst der ZWEITE Aufruf formularHolen:19 scheitert - kommt vorher noch
	 * einer aus grundOhneNachricht dazu, faellt das Verschieben schon beim
	 * Einlesen und nicht erst beim Zuruecklesen danach.
	 */
	public function testOhneTextWirdKeinFormularZusaetzlichGelesen(): void {
		$doppel = $this->bestand();
		$doppel->laessBeimZweitenMalScheitern('formularHolen:19');

		$antwort = $this->controller($this->felder(), doppel: $doppel)->ausfuehren();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertSame('Das Verschieben brach ab', $antwort->getParams()['titel']);
	}

	public function testEineBelegteSperreBehaeltDasGetippte(): void {
		$antwort = $this->controller($this->nachrichtfelder(), sperreBelegt: true,
			doppel: $this->bestandMitAdressen())->ausfuehren();

		$this->assertSame('verschiebevorschau', $antwort->getTemplateName());
		$this->assertSame('Hallo {vorname}, neu: {neuer_termin}', $antwort->getParams()['textWartende']);
		$this->assertSame([], $this->versand->adressen());
	}

	public function testDasProtokollNenntVerschiebungUndNachricht(): void {
		$this->controller($this->nachrichtfelder(), doppel: $this->bestandMitAdressen())->ausfuehren();

		$vorgaenge = array_map(static fn (Vorgang $zeile): string => $zeile->vorgang, $this->protokoll->zeilen);
		$this->assertSame(['kurs.verschoben', 'kurs.benachrichtigt'], $vorgaenge);
	}

	public function testEineScheiterndeMailNenntDenNamen(): void {
		$this->versand->laessScheiternBei('otto.warte@example.org');

		$daten = $this->controller($this->nachrichtfelder(), doppel: $this->bestandMitAdressen())
			->ausfuehren()->getParams();

		$this->assertSame('1 Nachricht ist hinausgegangen.', $daten['nachricht']);
		$this->assertSame(['Otto Warte'], $daten['gescheitert']);
	}

	/**
	 * Ein Neuladen schickt den POST noch einmal. Aendert sich der Termin,
	 * gibt es die alte Kennung danach nicht mehr; bleibt nur die Frist
	 * gleich, lehnt Verschiebeplan den zweiten Aufruf ab, weil sich dadurch
	 * nichts mehr aendern wuerde. Beides haelt eine zweite Nachricht auf.
	 */
	public function testEinZweiterAbsendenNachDemVerschiebenSchicktKeineZweiteNachricht(): void {
		$doppel = $this->bestandMitAdressen();

		$this->controller($this->nachrichtfelder(), doppel: $doppel)->ausfuehren();
		$zweiteAntwort = $this->controller($this->nachrichtfelder(), doppel: $doppel)->ausfuehren();

		$this->assertCount(2, $this->versand->adressen());
		$this->assertNotSame('verschoben', $zweiteAntwort->getTemplateName());
	}

	/**
	 * Bleibt der Termin gleich und aendert sich nur der Anmeldeschluss, gibt
	 * es die Kennung nach dem ersten Verschieben weiter. Der zweite Aufruf
	 * scheitert trotzdem: Verschiebeplan lehnt ihn ab, weil sich dadurch
	 * nichts mehr aendert - bevor irgendetwas verschickt wird.
	 */
	public function testEinZweiterAbsendenBeiReinerFristaenderungSchicktKeineZweiteNachricht(): void {
		$doppel = $this->bestandMitAdressen();
		$felder = array_merge($this->nachrichtfelder(), [
			'von' => '2026-09-12',
			'bis' => '2026-09-13',
			'anmeldeschluss' => '2026-09-01',
		]);

		$ersteAntwort = $this->controller($felder, doppel: $doppel)->ausfuehren();
		$zweiteAntwort = $this->controller($felder, doppel: $doppel)->ausfuehren();

		$this->assertSame('verschoben', $ersteAntwort->getTemplateName());
		$this->assertCount(2, $this->versand->adressen());
		$this->assertSame('meldung', $zweiteAntwort->getTemplateName());
		$this->assertSame('Die Angaben passen nicht', $zweiteAntwort->getParams()['titel']);
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
