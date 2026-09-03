<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Einstellungen;

use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class ZugangsdatenTest extends TestCase {
	/** @param array<string, string> $werte */
	private function konfigMit(array $werte): IAppConfig {
		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $schluessel, string $vorgabe = '')
					=> $werte[$schluessel] ?? $vorgabe,
			);
		return $konfig;
	}

	/**
	 * Merkt sich jeden Schreibvorgang samt sensitive-Flag.
	 *
	 * Das Flag ist der eigentliche Punkt: Ohne es liegt das Passwort im
	 * Klartext in der Datenbank, und niemandem faellt es auf.
	 *
	 * @param list<array{string, string, bool}> $geschrieben
	 */
	private function konfigDieMitschreibt(array &$geschrieben): IAppConfig {
		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('setValueString')->willReturnCallback(
			static function (
				string $app,
				string $schluessel,
				string $wert,
				bool $lazy = false,
				bool $sensitive = false,
			) use (&$geschrieben): bool {
				$geschrieben[] = [$schluessel, $wert, $sensitive];
				return true;
			},
		);
		return $konfig;
	}

	public function testDasPasswortWirdAlsSensibelGeschrieben(): void {
		$geschrieben = [];
		$zugangsdaten = new Zugangsdaten($this->konfigDieMitschreibt($geschrieben));

		$zugangsdaten->setzeAppPasswort('geheim');

		$this->assertSame([['app_passwort', 'geheim', true]], $geschrieben);
	}

	/**
	 * Die uebrigen drei sind keine Geheimnisse. Waeren sie sensibel,
	 * verschwaende Nextcloud Verschluesselung an eine Adresse, die ohnehin
	 * im Browser steht.
	 */
	public function testDieUebrigenWerteSindNichtSensibel(): void {
		$geschrieben = [];
		$zugangsdaten = new Zugangsdaten($this->konfigDieMitschreibt($geschrieben));

		$zugangsdaten->setzeBasisUrl('https://cloud.beispiel.invalid');
		$zugangsdaten->setzeBenutzer('radfahrschule');
		$zugangsdaten->setzeFreigabeGruppe('Radfahrschule');

		$this->assertSame([
			['basis_url', 'https://cloud.beispiel.invalid', false],
			['benutzer', 'radfahrschule', false],
			['freigabe_gruppe', 'Radfahrschule', false],
		], $geschrieben);
	}

	public function testLiestDieWerteAusDerAppKonfiguration(): void {
		$zugangsdaten = new Zugangsdaten($this->konfigMit([
			'basis_url' => 'https://cloud.beispiel.invalid',
			'benutzer' => 'radfahrschule',
			'app_passwort' => 'geheim',
		]));

		$this->assertSame('https://cloud.beispiel.invalid', $zugangsdaten->basisUrl());
		$this->assertSame('radfahrschule', $zugangsdaten->benutzer());
		$this->assertTrue($zugangsdaten->sindVollstaendig());
	}

	// Unvollstaendig heisst: Die Uebersicht sagt, was zu tun ist, statt in
	// einen Netzwerkfehler zu laufen, den niemand deuten kann.
	public function testFehlendesPasswortMachtSieUnvollstaendig(): void {
		$zugangsdaten = new Zugangsdaten($this->konfigMit([
			'basis_url' => 'https://cloud.beispiel.invalid',
			'benutzer' => 'radfahrschule',
		]));

		$this->assertFalse($zugangsdaten->sindVollstaendig());
	}

	public function testEinSchraegstrichAmEndeStoertNicht(): void {
		$zugangsdaten = new Zugangsdaten($this->konfigMit([
			'basis_url' => 'https://cloud.beispiel.invalid/',
			'benutzer' => 'radfahrschule',
			'app_passwort' => 'geheim',
		]));

		$this->assertSame('https://cloud.beispiel.invalid', $zugangsdaten->basisUrl());
	}
}
