<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use OCA\Radfahrschule\Formulare\Formular;

final class Kursliste {
	/**
	 * Buendelt Formulare zu Kursen. Vorlagen bleiben draussen: Sie sind
	 * Muster zum Klonen, kein laufender Kurs.
	 *
	 * Ein Kurs braucht eine Anmeldung oder eine Warteliste. Formulare ohne
	 * Art allein bilden keinen: Sonst stuende jedes fremde Formular des
	 * Dienstkontos in der Uebersicht und liesse sich dort samt Antworten
	 * loeschen.
	 *
	 * @param list<Formular> $formulare
	 * @return list<Kurs>
	 */
	public static function ausFormularen(array $formulare, Titelmuster $titelmuster): array {
		$kurse = [];

		foreach ($formulare as $formular) {
			$zerlegt = $titelmuster->zerlege($formular->titel);

			if ($zerlegt->art === Formularart::Vorlage) {
				continue;
			}

			$kennung = $zerlegt->kennung;
			if (!isset($kurse[$kennung])) {
				$kurse[$kennung] = new Kurs(
					kennung: $kennung,
					letzterTag: Kurstag::letzterAus($kennung),
					ersterTag: Kurstag::ersterAus($kennung),
				);
			}

			self::legeAb($kurse[$kennung], $zerlegt->art, $formular);
		}

		$echteKurse = array_filter($kurse, self::hatEineHaelfte(...));

		return array_values($echteKurse);
	}

	private static function hatEineHaelfte(Kurs $kurs): bool {
		return $kurs->anmeldung !== null || $kurs->warteliste !== null;
	}

	/**
	 * Kommende Kurse zuerst, der naechste zuoberst; darunter die
	 * vergangenen, der juengste zuerst; ganz unten, was kein Datum hat.
	 *
	 * Zum Loeschen scrollt man nach unten - dort steht, was die
	 * Aufbewahrungsfrist ueberschritten hat.
	 *
	 * @param list<Kurs> $kurse
	 * @return list<Kurs>
	 */
	public static function naechsterZuerst(array $kurse, DateTimeImmutable $jetzt): array {
		$heute = self::kalendertag($jetzt);

		usort($kurse, static function (Kurs $einer, Kurs $anderer) use ($heute): int {
			$tagEiner = $einer->letzterTag;
			$tagAnderer = $anderer->letzterTag;

			if ($tagEiner === null && $tagAnderer === null) {
				return 0;
			}
			if ($tagEiner === null) {
				return 1;
			}
			if ($tagAnderer === null) {
				return -1;
			}

			$einerKommt = $tagEiner >= $heute;
			$andererKommt = $tagAnderer >= $heute;

			if ($einerKommt !== $andererKommt) {
				return $einerKommt ? -1 : 1;
			}

			// Kommende aufsteigend (der naechste zuerst),
			// vergangene absteigend (der juengste zuerst).
			return $einerKommt
				? $tagEiner <=> $tagAnderer
				: $tagAnderer <=> $tagEiner;
		});

		return $kurse;
	}

	/** Schneidet die Uhrzeit ab. Zur Zone siehe Zeitzone::ABLAGE. */
	private static function kalendertag(DateTimeImmutable $zeitpunkt): DateTimeImmutable {
		return new DateTimeImmutable(
			$zeitpunkt->format('Y-m-d') . ' 00:00:00',
			Zeitzone::zumAblegen(),
		);
	}

	/**
	 * Legt ein Formular an seinem Platz im Kurs ab.
	 *
	 * Ein besetzter Platz wird NICHT ueberschrieben, sondern das Formular
	 * hinten angehaengt. Ueberschreiben hiesse: Der Dienst kennt das zweite
	 * Formular derselben Kennung nicht mehr - es bliebe mitsamt seinen
	 * Anmeldedaten stehen, waehrend die Oberflaeche Vollstaendigkeit meldet.
	 *
	 * Verwerfen waere ebenso falsch, und beim Loeschen teuer: Kurs::zeilen()
	 * ist zugleich die Loeschliste.
	 */
	private static function legeAb(Kurs $kurs, Formularart $art, Formular $formular): void {
		if ($art === Formularart::Anmeldung && $kurs->anmeldung === null) {
			$kurs->anmeldung = $formular;
			return;
		}
		if ($art === Formularart::Warteliste && $kurs->warteliste === null) {
			$kurs->warteliste = $formular;
			return;
		}
		if ($art === Formularart::Unbekannt && $kurs->sonstiges === null) {
			$kurs->sonstiges = $formular;
			return;
		}

		$kurs->weitere[] = $formular;
	}
}
