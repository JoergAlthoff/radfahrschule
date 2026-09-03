<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Rechte;

use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class VerwaltungsrechtTest extends TestCase {
	private function recht(
		?string $angemeldetAls,
		string $gruppe,
		bool $istDrin,
		bool $istAdmin = false,
	): Verwaltungsrecht {
		$userSession = $this->createStub(IUserSession::class);
		if ($angemeldetAls === null) {
			$userSession->method('getUser')->willReturn(null);
		} else {
			$benutzer = $this->createStub(IUser::class);
			$benutzer->method('getUID')->willReturn($angemeldetAls);
			$userSession->method('getUser')->willReturn($benutzer);
		}

		$groupManager = $this->createStub(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn($istDrin);
		$groupManager->method('isAdmin')->willReturn($istAdmin);

		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('freigabeGruppe')->willReturn($gruppe);

		return new Verwaltungsrecht($userSession, $groupManager, $zugangsdaten);
	}

	public function testWerInDerGruppeStehtDarfVerwalten(): void {
		$this->assertTrue($this->recht('anna', 'Radfahrschule', true)->darfVerwalten());
	}

	public function testWerNichtInDerGruppeStehtDarfNicht(): void {
		$this->assertFalse($this->recht('karl', 'Radfahrschule', false)->darfVerwalten());
	}

	/**
	 * Ein Admin darf dasselbe wie die Gruppe. Ein Schutz dagegen waere
	 * Fassade: Er koennte sich jederzeit selbst in die Gruppe eintragen.
	 */
	public function testEinAdminDarfAuchOhneGruppe(): void {
		$this->assertTrue(
			$this->recht('chefin', 'Radfahrschule', false, true)->darfVerwalten());
	}

	/**
	 * Beide Seiten des ODER einzeln geprueft - sonst liesse es sich in ein
	 * UND verwandeln, ohne dass etwas rot wird.
	 */
	public function testWerInDerGruppeStehtDarfAuchOhneAdminrecht(): void {
		$this->assertTrue(
			$this->recht('anna', 'Radfahrschule', true, false)->darfVerwalten());
	}

	public function testEinAdminOhneAnmeldungDarfNicht(): void {
		$this->assertFalse(
			$this->recht(null, 'Radfahrschule', false, true)->darfVerwalten());
	}

	/**
	 * Ohne konfigurierte Gruppe darf auch der Admin nicht. Sonst stuende die
	 * App bei einer halb eingerichteten Instanz fuer ihn offen.
	 */
	public function testOhneKonfigurierteGruppeDarfAuchDerAdminNicht(): void {
		$this->assertFalse($this->recht('chefin', '', false, true)->darfVerwalten());
	}

	public function testOhneAngemeldetenBenutzerDarfNiemand(): void {
		$this->assertFalse($this->recht(null, 'Radfahrschule', true)->darfVerwalten());
	}

	/**
	 * Eine leere Einstellung, die alle durchliesse, waere die gefaehrlichere
	 * Richtung: Sie fiele niemandem auf.
	 */
	public function testOhneKonfigurierteGruppeDarfNiemand(): void {
		$this->assertFalse($this->recht('anna', '', true)->darfVerwalten());
	}

	public function testDerBenutzernameKommtAusDerSitzung(): void {
		$this->assertSame('anna', $this->recht('anna', 'Radfahrschule', true)->benutzer());
	}

	public function testOhneAnmeldungIstDerBenutzernameLeer(): void {
		$this->assertSame('', $this->recht(null, 'Radfahrschule', true)->benutzer());
	}
}
