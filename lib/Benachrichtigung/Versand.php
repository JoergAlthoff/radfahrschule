<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Benachrichtigung;

use OCA\Radfahrschule\Formulare\Empfaenger;

/**
 * Die Naht zum Mailversand.
 *
 * Dasselbe Muster wie bei Formulare und Protokoll: Die Fachlogik kennt nur
 * dieses Interface, und die Tests verschicken nichts.
 */
interface Versand {
	/**
	 * Ist der Versand auf der Instanz ausdruecklich abgeschaltet?
	 *
	 * Mehr laesst sich vorher nicht wissen. Nextcloud sagt ueber keine
	 * oeffentliche Schnittstelle, ob ein Mailversand eingerichtet ist.
	 */
	public function istAbgeschaltet(): bool;

	/**
	 * Eine Mail an genau einen Empfaenger.
	 *
	 * Gibt false zurueck, wenn sie nicht hinausging. Wirft nicht: Eine
	 * scheiternde Mail darf die uebrigen nicht aufhalten.
	 */
	public function schicke(Empfaenger $empfaenger, Nachricht $nachricht): bool;
}
