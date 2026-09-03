<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use RuntimeException;

/**
 * Tauscht die kursabhaengigen Stellen im geklonten Text aus.
 *
 * Beide Methoden erwarten GENAU einen Treffer. Kein Treffer heisst: Jemand
 * hat die Stelle in der Vorlage umformuliert - dann muss es laut scheitern,
 * sonst traegt der neue Kurs still die Angaben des alten.
 *
 * Nicht die Platzhalter sind das Kriterium, sondern ihr Umfeld. Was dahinter
 * steht, darf heissen, wie es will.
 */
final class Textersetzung {
	/** Leitet die Zeile ein, die den Kurstermin traegt. */
	private const TERMIN_PRAEFIX = '- **Termin:** ';

	/** Steht vor dem Hash in jedem oeffentlichen Formularlink. */
	private const FREIGABE_PFAD = '/apps/forms/s/';

	public static function terminZeile(string $bedingungstext, string $neuerTermin): string {
		$zeilen = explode("\n", $bedingungstext);
		$treffer = 0;

		foreach ($zeilen as $nummer => $zeile) {
			if (!str_starts_with(trim($zeile), self::TERMIN_PRAEFIX)) {
				continue;
			}
			// Die Einrueckung bleibt stehen: In Markdown macht sie aus einem
			// Listenpunkt einen verschachtelten.
			$einrueckung = substr($zeile, 0, strlen($zeile) - strlen(ltrim($zeile, " \t")));
			$zeilen[$nummer] = $einrueckung . self::TERMIN_PRAEFIX . $neuerTermin;
			$treffer++;
		}

		if ($treffer !== 1) {
			throw new RuntimeException(sprintf(
				'Die Terminzeile „%s" kommt %d-mal vor, erwartet war genau einmal.',
				trim(self::TERMIN_PRAEFIX), $treffer));
		}

		return implode("\n", $zeilen);
	}

	public static function wartelistenLink(string $beschreibung, string $neuerHash): string {
		$teile = explode(self::FREIGABE_PFAD, $beschreibung);
		if (count($teile) !== 2) {
			throw new RuntimeException(sprintf(
				'Der Wartelisten-Link „%s" kommt %d-mal vor, erwartet war genau einmal.',
				self::FREIGABE_PFAD, count($teile) - 1));
		}

		$rest = $teile[1];
		$hashLaenge = 0;
		while ($hashLaenge < strlen($rest) && self::istHashZeichen($rest[$hashLaenge])) {
			$hashLaenge++;
		}

		return $teile[0] . self::FREIGABE_PFAD . $neuerHash . substr($rest, $hashLaenge);
	}

	/**
	 * Nextcloud vergibt fuer den Hash Buchstaben und Ziffern; alles andere
	 * beendet ihn.
	 */
	private static function istHashZeichen(string $zeichen): bool {
		return ctype_alnum($zeichen);
	}
}
