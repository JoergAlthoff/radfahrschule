<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeZone;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;

/**
 * Die beiden Zeitzonen dieser App - und nur eine davon ist eine Einstellung.
 *
 * Welcher Tag "heute" ist und wann ein Anmeldeschluss ablaeuft, entscheidet
 * die Zone, in der die Kurse stattfinden. Sie steht in den Einstellungen:
 * Ein anderer Betreiber sitzt anderswo. Deshalb ist desVereins() eine
 * Methode am Objekt und zumAblegen() eine statische - die zweite ist fest.
 *
 * Nicht UTC und nicht die Servereinstellung.
 *
 * Nextcloud stellt die PHP-Zeitzone immer auf UTC (siehe IDateTimeZone).
 * Das ist der Speicherboden, nicht die Antwort auf eine Frage nach
 * Kalendertagen: Zwischen Mitternacht und zwei Uhr ist in UTC noch der
 * Vortag.
 *
 * Es gaebe auch IDateTimeZone::getDefaultTimeZone(). Das ist die Zone des
 * SERVERS - sie verschoebe die Kurstermine, sobald jemand sie aendert,
 * obwohl die Kurse weiter am selben Ort stattfinden.
 */
class Zeitzone {
	public function __construct(private Betreiberangaben $betreiberangaben) {
	}

	/**
	 * Die Zone, in der Kalendertage abgelegt werden.
	 *
	 * PHP kennt kein Datum ohne Uhrzeit - ein Kurstag muss deshalb als
	 * Zeitpunkt um Mitternacht gebaut werden, und dazu gehoert eine Zone.
	 * WELCHE es ist, ist folgenlos: Verglichen wird nur Kalendertag gegen
	 * Kalendertag, und eine andere Zone verschoebe beide gleich weit.
	 *
	 * Folgenreich ist allein, dass ueberall DIESELBE steht. Mitternacht in
	 * Berlin ist 23:00 UTC des Vortags - ein Berliner Kalendertag gegen
	 * einen in UTC verglichen liegt einen Tag daneben.
	 *
	 * UTC, weil es keine Sommerzeit kennt. Berlin springt zweimal im Jahr.
	 */
	public const string ABLAGE = 'UTC';

	/** Welchen Kalendertag zeigt die Uhr gerade? Siehe oben. */
	public function desVereins(): DateTimeZone {
		return new DateTimeZone($this->betreiberangaben->zeitzone());
	}

	/** Wohin mit einem abgelesenen Kalendertag? Siehe ABLAGE. */
	public static function zumAblegen(): DateTimeZone {
		return new DateTimeZone(self::ABLAGE);
	}
}
