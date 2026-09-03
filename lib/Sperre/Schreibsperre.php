<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Sperre;

use Throwable;
use OCA\Radfahrschule\Fachlogik\GeradeBeschaeftigt;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Laesst immer nur EINEN schreibenden Vorgang zur Zeit laufen.
 *
 * Sie schliesst die Luecke, die eine Pruefung allein offen laesst: Zwischen
 * dem Lesen der Formularliste und dem Moment, in dem der neue Kurs seinen
 * Titel traegt, liegen mehrere Aufrufe. Der Klon heisst bis dahin
 * "VORLAGE … - Kopie" und ist unter seiner Kennung nicht auffindbar; zwei
 * Anfragen in diesem Fenster lesen beide eine Liste ohne den Kurs und legen
 * beide an. Ein Doppelklick liegt bei 100 bis 300 Millisekunden.
 *
 * Anlegen UND Verschieben teilen sie sich: Beide vergeben einen Titel. Zwei
 * getrennte Sperren liessen genau dieses Rennen zu - ohne Sperre entstehen
 * zwei Paare mit identischem Titel. Der Schluessel heisst deshalb weiter
 * "kurs-anlegen": Er ist kein Name, sondern eine Adresse, und ein zweiter
 * machte aus einer Sperre zwei.
 *
 * Genommen wird sie ohne Warten: Wer ankommt, waehrend jemand schreibt, wird
 * sofort abgewiesen. Ein Warten koennte das Zeitlimit reissen, waehrend der
 * erste Lauf im Hintergrund einen vollstaendigen Kurs anlegt.
 *
 * Sie wirkt ueber alle PHP-Prozesse. In PHP ist jeder Aufruf ein eigener
 * Prozess - eine Sperre im Speicher gaebe es gar nicht.
 *
 * Steht filelocking.enabled auf false, liefert Nextcloud einen
 * NoopLockingProvider und die Sperre ist wirkungslos, ohne dass etwas
 * fehlschlaegt. Dies ist die Stelle, an der das auffaellt: Mit Sperre
 * entsteht EIN Kurs, ohne sie entstehen zwei Paare mit identischem
 * Titel.
 */
final readonly class Schreibsperre {
	/**
	 * Der Schluessel der Sperre. Er sieht aus wie ein Pfad, weil der
	 * Provider urspruenglich fuer Dateien gedacht ist - gesperrt wird aber
	 * schlicht dieser eine Name.
	 */
	private const SCHLUESSEL = 'radfahrschule/kurs-anlegen';

	public function __construct(
		private ILockingProvider $lockingProvider,
		private LoggerInterface $logger,
	) {
	}

	/** @throws GeradeBeschaeftigt */
	public function nimm(): void {
		try {
			$this->lockingProvider->acquireLock(
				self::SCHLUESSEL, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			throw new GeradeBeschaeftigt();
		}
	}

	/**
	 * Gibt die Sperre zurueck. Wirft nie.
	 *
	 * Sie laeuft im finally-Zweig des Anlegens; eine Ausnahme hier
	 * verdraengte die eigentliche Fehlermeldung des Laufs. Verschluckt wird
	 * sie trotzdem nicht - sie geht ins Protokoll.
	 */
	public function gib(): void {
		try {
			$this->lockingProvider->releaseLock(
				self::SCHLUESSEL, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (Throwable $fehler) {
			// Bleibt sie liegen, laeuft sie nach der TTL des Providers ab -
			// und bis dahin sagt die App jedem "Gerade beschäftigt", ohne
			// dass jemand den Grund finden koennte. Deshalb die Zeile.
			$this->logger->error('Die Schreibsperre ließ sich nicht zurückgeben.', [
				'app' => 'radfahrschule',
				'ursache' => $fehler::class . ': ' . $fehler->getMessage(),
			]);
		}
	}
}
