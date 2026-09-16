<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use OCA\Radfahrschule\Controller\EinstellungenController;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Settings\Einstellungsbereich;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EinstellungenControllerTest extends TestCase {
	/** @var (Zugangsdaten&MockObject)|null */
	private ?Zugangsdaten $zugangsdaten = null;

	/** @var (Betreiberangaben&MockObject)|null */
	private ?Betreiberangaben $angaben = null;

	/**
	 * Das Doppel entsteht erst beim ersten Zugriff.
	 *
	 * Nicht in setUp: PHPUnit 13 meldet einen Hinweis fuer jedes Doppel, an
	 * dem kein Test eine Erwartung setzt - und der Test auf den Ruecksprung
	 * setzt keine.
	 *
	 * @return Zugangsdaten&MockObject
	 */
	private function doppel(): Zugangsdaten {
		return $this->zugangsdaten ??= $this->createMock(Zugangsdaten::class);
	}

	/**
	 * Dasselbe fuer die Angaben des Betreibers.
	 *
	 * @return Betreiberangaben&MockObject
	 */
	private function angabenDoppel(): Betreiberangaben {
		return $this->angaben ??= $this->createMock(Betreiberangaben::class);
	}

	/**
	 * @param array<string, string> $felder
	 * @param Zugangsdaten|null $zugangsdaten Ohne Angabe das Doppel mit den
	 *   Erwartungen.
	 */
	private function controller(
		array $felder,
		?Zugangsdaten $zugangsdaten = null,
		?IURLGenerator $urlGenerator = null,
	): EinstellungenController {
		$request = $this->createStub(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $feld, mixed $vorgabe = null): mixed => $felder[$feld] ?? $vorgabe,
		);

		if ($urlGenerator === null) {
			$urlGenerator = $this->createStub(IURLGenerator::class);
			$urlGenerator->method('linkToRoute')->willReturn('/settings/admin/radfahrschule');
		}

		// Nur die Doppel, an denen ein Test wirklich eine Erwartung
		// gesetzt hat. Sonst meldet PHPUnit 13 fuer jedes unbenutzte einen
		// Hinweis - und die Hinweise verdecken die echten.
		return new EinstellungenController(
			'radfahrschule',
			$request,
			$zugangsdaten ?? $this->zugangsdaten ?? $this->createStub(Zugangsdaten::class),
			$this->angaben ?? $this->createStub(Betreiberangaben::class),
			$urlGenerator,
		);
	}

	public function testDieDreiOffenenWerteWerdenGeschrieben(): void {
		$this->doppel()->expects($this->once())
			->method('setzeBasisUrl')->with('https://cloud.beispiel.invalid');
		$this->doppel()->expects($this->once())
			->method('setzeBenutzer')->with('radfahrschule');
		$this->doppel()->expects($this->once())
			->method('setzeFreigabeGruppe')->with('Radfahrschule');

		$this->controller([
			'basisUrl' => 'https://cloud.beispiel.invalid',
			'benutzer' => 'radfahrschule',
			'freigabeGruppe' => 'Radfahrschule',
		])->speichere();
	}

	/**
	 * Die Seite gibt das Passwort nie zurueck. Es kaeme also bei jedem
	 * Speichern leer wieder an - und wuerde den Wert loeschen, den niemand
	 * anfassen wollte.
	 */
	public function testEinLeeresPasswortfeldLaesstDenAltenWertStehen(): void {
		$this->doppel()->expects($this->never())->method('setzeAppPasswort');

		$this->controller(['basisUrl' => 'https://cloud.beispiel.invalid'])->speichere();
	}

	public function testEinAusgefuelltesPasswortfeldUeberschreibt(): void {
		$this->doppel()->expects($this->once())
			->method('setzeAppPasswort')->with('geheim');

		$this->controller(['appPasswort' => 'geheim'])->speichere();
	}

	/**
	 * Ein Passwort mit Randleerzeichen waere ein anderes - deshalb bleibt es
	 * ungetrimmt? Nein: Ein fuehrendes Leerzeichen ist beim Einfuegen aus der
	 * Zwischenablage ein Versehen, und der Fehler faellt erst beim naechsten
	 * Aufruf von Forms auf. Getrimmt wird alles.
	 */
	public function testRandleerzeichenWerdenAbgeschnitten(): void {
		$this->doppel()->expects($this->once())
			->method('setzeBenutzer')->with('radfahrschule');
		$this->doppel()->expects($this->once())
			->method('setzeAppPasswort')->with('geheim');

		$this->controller([
			'benutzer' => "  radfahrschule\t",
			'appPasswort' => ' geheim ',
		])->speichere();
	}

	/**
	 * Nach dem Speichern zurueck zur Einstellungsseite. Ohne den Sprung
	 * bliebe der Browser auf einer leeren Antwort stehen, und niemand wuesste,
	 * ob etwas passiert ist.
	 *
	 * In den EIGENEN Bereich, nicht nach "additional". Zeigte der Sprung
	 * woandershin, landete man auf einer fremden Seite, auf der die eigenen
	 * Einstellungen gar nicht stehen.
	 *
	 * Geprueft wird der ROUTENNAME, nicht die Adresse. Ein Stub, das auf
	 * jeden Namen dieselbe Adresse zurueckgibt, haelt gar nichts: Damit
	 * bliebe der Test auch bei einer falschen Route gruen.
	 */
	public function testNachDemSpeichernGehtEsZurueckZurEinstellungsseite(): void {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->expects($this->once())
			->method('linkToRoute')
			->with('settings.AdminSettings.index',
				['section' => Einstellungsbereich::KENNUNG])
			->willReturn('/settings/admin/radfahrschule');

		$antwort = $this->controller(
			['benutzer' => 'radfahrschule'],
			$this->createStub(Zugangsdaten::class),
			$urlGenerator,
		)->speichere();

		$this->assertSame('/settings/admin/radfahrschule', $antwort->getRedirectURL());
	}

	/**
	 * Die fuenf Angaben des Betreibers werden geschrieben.
	 *
	 * Der Name ohne Trenner - den haengt titelPraefix() an. Wer ihn selbst
	 * tippen muesste, braeuchte einen Gedankenstrich.
	 */
	public function testDieAngabenDesBetreibersWerdenGeschrieben(): void {
		$this->angabenDoppel()->expects($this->once())
			->method('setzeName')->with('Radfahrschule Musterstadt');
		$this->angabenDoppel()->expects($this->once())
			->method('setzeKursart')->with('Anfängerkurs');
		$this->angabenDoppel()->expects($this->once())
			->method('setzeAufbewahrungTage')->with(90);
		$this->angabenDoppel()->expects($this->once())
			->method('setzeZeitzone')->with('Europe/Berlin');
		$this->angabenDoppel()->expects($this->once())
			->method('setzeTerminportalHinweis')->with('Auch ins Terminportal.');

		$this->controller([
			'name' => 'Radfahrschule Musterstadt',
			'kursart' => 'Anfängerkurs',
			'aufbewahrungTage' => '90',
			'zeitzone' => 'Europe/Berlin',
			'terminportalHinweis' => 'Auch ins Terminportal.',
		])->speichere();
	}

	/**
	 * Ein leeres Feld ergibt null Tage, und das hiesse "sofort loeschen".
	 * Geschrieben wird deshalb eine Null, und Betreiberangaben faellt beim
	 * Lesen auf die Vorbelegung zurueck.
	 */
	public function testEineUnbrauchbareFristWirdAlsNullGeschrieben(): void {
		$this->angabenDoppel()->expects($this->once())
			->method('setzeAufbewahrungTage')->with(0);

		$this->controller(['aufbewahrungTage' => 'neunzig'])->speichere();
	}

	/** Ein Feld, das der Browser gar nicht mitschickt, wird zum leeren Wert. */
	public function testEinFehlendesFeldWirdZumLeerenWert(): void {
		$this->doppel()->expects($this->once())->method('setzeBasisUrl')->with('');
		$this->doppel()->expects($this->never())->method('setzeAppPasswort');

		$this->controller([])->speichere();
	}

	public function testDieAntwortadresseWirdGeschrieben(): void {
		$this->angabenDoppel()->expects($this->once())
			->method('setzeAntwortadresse')->with('kurse@example.org');

		$this->controller(['antwortadresse' => ' kurse@example.org '])->speichere();
	}

	public function testDieAbsagetexteWerdenGeschrieben(): void {
		$angaben = $this->angabenDoppel();
		$angaben->expects($this->once())->method('setzeAbsageBetreff')->with('Absage');
		$angaben->expects($this->once())->method('setzeAbsageTextAngemeldete')->with("Zeile 1\nZeile 2");
		$angaben->expects($this->once())->method('setzeAbsageTextWartende')->with('An Wartende');

		$this->controller([
			'absageBetreff' => ' Absage ',
			'absageTextAngemeldete' => "Zeile 1\nZeile 2",
			'absageTextWartende' => 'An Wartende',
		])->speichere();
	}

	public function testDieTexteDerVerschiebungWerdenGeschrieben(): void {
		$angaben = $this->angabenDoppel();
		$angaben->expects($this->once())->method('setzeVerschiebungBetreff')->with('Verschoben');
		$angaben->expects($this->once())->method('setzeVerschiebungTextAngemeldete')->with("Zeile 1\nZeile 2");
		$angaben->expects($this->once())->method('setzeVerschiebungTextWartende')->with('An Wartende');

		$this->controller([
			'verschiebungBetreff' => ' Verschoben ',
			'verschiebungTextAngemeldete' => "Zeile 1\nZeile 2",
			'verschiebungTextWartende' => 'An Wartende',
		])->speichere();
	}
}
