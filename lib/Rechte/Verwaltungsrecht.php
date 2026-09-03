<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Rechte;

use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Wer Kurse anlegen, verschieben und loeschen darf.
 *
 * Die Rechte kommen aus der Nextcloud-Gruppe, nicht aus einer eigenen Datei.
 * Der Go-Vorgaenger hatte dafuer eine Zugangsdatei mit Rollen; hier
 * entscheidet die Mitgliedschaft, und wer dazukommt, wird in Nextcloud
 * aufgenommen. Ein Admin darf dasselbe wie die Gruppe.
 *
 * Dasselbe Recht entscheidet auch, ob jemand die App ueberhaupt oeffnen
 * darf - die Uebersicht und die Kursseite fragen es ebenso.
 *
 * Der Name sagt die Berechtigung, nicht die Handlung: Geprueft wird dieselbe
 * fuer Anlegen, Verschieben UND Loeschen.
 *
 * NICHT final: Der Controller-Test ersetzt diese Klasse durch ein Doppel, und
 * PHPUnit kann eine final-Klasse nicht ersetzen. Dieselbe Ueberlegung wie bei
 * Zugangsdaten.
 */
readonly class Verwaltungsrecht {
	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private Zugangsdaten $zugangsdaten,
	) {
	}

	public function darfVerwalten(): bool {
		$gruppe = $this->zugangsdaten->freigabeGruppe();
		if ($gruppe === '') {
			// Ohne konfigurierte Gruppe darf niemand. Die andere Richtung -
			// alle duerfen - fiele niemandem auf.
			return false;
		}

		$benutzer = $this->userSession->getUser();
		if ($benutzer === null) {
			return false;
		}

		// Ein Admin darf dasselbe wie die Gruppe. Ihn auszusperren waere
		// Fassade: Er koennte sich jederzeit selbst eintragen.
		return $this->groupManager->isInGroup($benutzer->getUID(), $gruppe)
			|| $this->groupManager->isAdmin($benutzer->getUID());
	}

	/** Der angemeldete Mensch. Er steht im Protokoll. */
	public function benutzer(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}
}
