<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;

/**
 * Liest die Kurstage aus der Kennung.
 *
 * Der Tag kommt aus dem Titel, NICHT aus dem Ablaufzeitpunkt des Formulars:
 * Der sagt bei Anmeldung und Warteliste Verschiedenes, und von Hand
 * angelegte Formulare tragen dort, was jemand eingetippt hat.
 *
 * Termin::kurz schreibt drei Formen, und alle drei muessen zurueckgelesen
 * werden koennen:
 *
 *   13.09.2026          ein Tag
 *   12./13.09.2026      zwei Tage im selben Monat
 *   30.09./01.10.2026   ueber einen Monatswechsel
 *
 * Der Vorsatz vor dem Schraegstrich traegt nie ein Jahr und im zweiten Fall
 * auch keinen Monat. Was fehlt, kommt vom letzten Tag.
 */
final class Kurstag {
	/** Der letzte Tag: immer vollstaendig, immer am Ende. */
	private const LETZTER = '/(\d{2})\.(\d{2})\.(\d{4})\s*$/u';

	/** Der Vorsatz davor. Der Monat ist optional, ein Jahr gibt es nie. */
	private const VORSATZ = '/(\d{2})\.(?:(\d{2})\.)?\/\d{2}\.\d{2}\.\d{4}\s*$/u';

	/**
	 * Gibt den letzten Kurstag zurueck, oder null bei einer Kennung ohne
	 * Datum. Bei "12./13.09.2026" ist das der 13.09.
	 */
	public static function letzterAus(string $kennung): ?DateTimeImmutable {
		if (preg_match(self::LETZTER, trim($kennung), $treffer) !== 1) {
			return null;
		}

		return self::baue($treffer[1], $treffer[2], $treffer[3]);
	}

	/**
	 * Gibt den ersten Kurstag zurueck. Bei "12./13.09.2026" ist das der
	 * 12.09.; ohne Schraegstrich ist er derselbe wie der letzte.
	 *
	 * Das Jahr kommt IMMER vom letzten Tag, weil der Vorsatz keines traegt.
	 * Ein Kurs ueber den Jahreswechsel bekaeme dadurch einen ersten Tag im
	 * falschen Jahr - dieselbe Luecke hat schon Termin::kurz beim Schreiben.
	 * Die Radfahrschule faehrt vier Kurse im Sommer; der Fall tritt nicht ein.
	 */
	public static function ersterAus(string $kennung): ?DateTimeImmutable {
		$letzter = self::letzterAus($kennung);
		if ($letzter === null) {
			return null;
		}

		if (preg_match(self::VORSATZ, trim($kennung), $treffer) !== 1) {
			// Kein Schraegstrich: ein eintaegiger Kurs.
			return $letzter;
		}

		$monat = ($treffer[2] ?? '') !== '' ? $treffer[2] : $letzter->format('m');

		return self::baue($treffer[1], $monat, $letzter->format('Y'));
	}

	/** Zur Zone siehe Zeitzone::ABLAGE. testDerTagStehtInUtc haelt sie fest. */
	private static function baue(string $tag, string $monat, string $jahr): ?DateTimeImmutable {
		$gebaut = DateTimeImmutable::createFromFormat(
			'd.m.Y H:i:s',
			sprintf('%s.%s.%s 00:00:00', $tag, $monat, $jahr),
			Zeitzone::zumAblegen(),
		);

		// Auf false allein ist kein Verlass: createFromFormat rollt einen
		// unmoeglichen Kalendertag still weiter, aus dem 31.09. wird der
		// 01.10. Die Uebersicht sortiert und fristet danach, und die
		// Kursseite nennte einen anderen Tag als ihre eigene Ueberschrift.
		//
		// getLastErrors() liefert genau dann ein Array, wenn beim Lesen etwas
		// nicht stimmte; sonst false.
		$sauberGelesen = DateTimeImmutable::getLastErrors() === false;

		return ($gebaut === false || !$sauberGelesen) ? null : $gebaut;
	}
}
