<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Einstellungen;

use OCP\IAppConfig;

/**
 * Die Zugangsdaten zur Forms-API der eigenen Instanz.
 *
 * Sie stehen in der App-Konfiguration, nicht in einer Datei: Nextcloud haelt
 * das Passwort verschluesselt und zeigt es nie wieder an.
 *
 * NICHT final: Der Test der REST-Anbindung ersetzt diese Klasse durch ein
 * Doppel, und PHPUnit kann eine final-Klasse nicht ersetzen.
 */
readonly class Zugangsdaten {
	private const APP = 'radfahrschule';

	public function __construct(private IAppConfig $appConfig) {
	}

	public function basisUrl(): string {
		return rtrim($this->appConfig->getValueString(self::APP, 'basis_url', ''), '/');
	}

	public function benutzer(): string {
		return $this->appConfig->getValueString(self::APP, 'benutzer', '');
	}

	public function appPasswort(): string {
		return $this->appConfig->getValueString(self::APP, 'app_passwort', '');
	}

	/**
	 * Die Nextcloud-Gruppe, die jedes neue Formular sehen soll - und deren
	 * Mitglieder Kurse verwalten duerfen.
	 *
	 * Eine Gruppe, zwei Zwecke. Damit ist sie die einzige Stelle, an der
	 * steht, wer mitarbeitet: Wer dazukommt, wird in Nextcloud aufgenommen,
	 * nicht hier eingetragen.
	 */
	public function freigabeGruppe(): string {
		return $this->appConfig->getValueString(self::APP, 'freigabe_gruppe', '');
	}

	public function setzeBasisUrl(string $wert): void {
		$this->appConfig->setValueString(self::APP, 'basis_url', $wert);
	}

	public function setzeBenutzer(string $wert): void {
		$this->appConfig->setValueString(self::APP, 'benutzer', $wert);
	}

	/**
	 * Als EINZIGER Wert sensibel geschrieben.
	 *
	 * Das letzte Argument ist der ganze Punkt: Ohne es legt Nextcloud das
	 * Passwort im Klartext in der Datenbank ab, und niemandem faellt es auf.
	 * Vorher liess sich der Wert nur mit "occ config:app:set --sensitive"
	 * setzen - und wer das --sensitive vergass, merkte nichts.
	 */
	public function setzeAppPasswort(string $wert): void {
		$this->appConfig->setValueString(
			self::APP, 'app_passwort', $wert, sensitive: true);
	}

	public function setzeFreigabeGruppe(string $wert): void {
		$this->appConfig->setValueString(self::APP, 'freigabe_gruppe', $wert);
	}

	public function sindVollstaendig(): bool {
		return $this->basisUrl() !== ''
			&& $this->benutzer() !== ''
			&& $this->appPasswort() !== '';
	}
}
