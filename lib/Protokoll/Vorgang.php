<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Protokoll;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Eine Zeile des Vorgangsprotokolls: wer wann welchen Kurs angelegt hat.
 *
 * Nextcloud kann das nicht. Die App meldet sich bei Forms mit EINEM Konto
 * an, und deshalb erscheint dort jeder Vorgang unter demselben Namen.
 *
 * Alle benannten Konstruktoren stehen zusammen in dieser Datei, obwohl sie
 * aus verschiedenen Ablaeufen gerufen werden. Der Grund ist das Format: Die
 * Feldnamen sind die Spaltennamen. Wer einen aendert, aendert das
 * Datenformat - und das faellt nur auf, wenn man alle nebeneinander sieht.
 *
 * Gemeinsam ist ihnen:
 *
 *   - "vorgang" ist der Anker beim Suchen und traegt den Punkt als Trenner
 *   - "benutzer" ist der angemeldete Mensch, nicht das Nextcloud-Konto
 *   - "kurstag" steht in ISO, damit sich danach sortieren laesst
 *   - Kein Personenbezug ausser dem Bearbeiter. Die Anmeldedaten selbst
 *     holt die App nie.
 */
final readonly class Vorgang {
	private function __construct(
		public DateTimeImmutable $zeitpunkt,
		public string $vorgang,
		public string $benutzer,
		public string $kennung,
		public string $kurstag,
		public ?int $anmeldungId,
		public ?int $wartelisteId,
		public ?int $plaetze,
		public ?int $anmeldungen,
		public ?string $grund,
	) {
	}

	/**
	 * Ein Kurspaar steht.
	 *
	 * Die Begruendung ist eine ANDERE als beim Loeschen: Hier entsteht ein
	 * leeres Formularpaar, es sind noch keine Anmeldedaten da, die zu
	 * schuetzen waeren. Der Grund ist betrieblich - ein Kurs mit falschem
	 * Datum im Titel laesst Leute am falschen Tag kommen, und dann will man
	 * wissen, wen man fragen kann.
	 */
	public static function angelegt(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $kennung,
		string $kurstag,
		int $anmeldungId,
		int $wartelisteId,
		int $plaetze,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.angelegt',
			benutzer: $benutzer,
			kennung: $kennung,
			kurstag: $kurstag,
			anmeldungId: $anmeldungId,
			wartelisteId: $wartelisteId,
			plaetze: $plaetze,
			anmeldungen: null,
			grund: null,
		);
	}

	/**
	 * Der Lauf scheiterte, und das Aufraeumen gelang.
	 *
	 * Er trennt sich vom Abbruch aus einem Grund, der beim Suchen zaehlt:
	 * Dieser Fall verlangt von niemandem etwas. Er nennt deshalb keine IDs -
	 * es gibt nichts mehr, worauf sie zeigen koennten.
	 */
	public static function zurueckgerollt(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $kennung,
		string $kurstag,
		string $grund,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.anlegen_zurueckgerollt',
			benutzer: $benutzer,
			kennung: $kennung,
			kurstag: $kurstag,
			anmeldungId: null,
			wartelisteId: null,
			plaetze: null,
			anmeldungen: null,
			grund: $grund,
		);
	}

	/**
	 * Der Lauf scheiterte, UND das Aufraeumen misslang. Nur dieser Fall
	 * verlangt, dass jemand etwas tut.
	 *
	 * Was stehenblieb, ist nicht zuzuordnen - halb benannt, mit dem Termin
	 * der Vorlage. Wer spaeter aufraeumt, findet hier die IDs.
	 */
	public static function anlegenAbgebrochen(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $kennung,
		string $kurstag,
		?int $anmeldungId,
		?int $wartelisteId,
		string $grund,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.anlegen_abgebrochen',
			benutzer: $benutzer,
			kennung: $kennung,
			kurstag: $kurstag,
			anmeldungId: $anmeldungId,
			wartelisteId: $wartelisteId,
			plaetze: null,
			anmeldungen: null,
			grund: $grund,
		);
	}

	/**
	 * Ein Kurspaar ist weg.
	 *
	 * Anders als beim Anlegen ist das SELBST eine Datenschutzmassnahme: Wer
	 * hier loescht, vernichtet die Anmeldedaten mehrerer Personen,
	 * unwiderruflich und ohne Papierkorb. Der Verein muss nachweisen
	 * koennen, dass damit ordentlich umgegangen wurde - Art. 5 Abs. 2 und
	 * Art. 32 DSGVO.
	 *
	 * "anmeldungen" ist eine ZAHL. Die Anmeldedaten selbst holt die App nie.
	 */
	public static function geloescht(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $kennung,
		string $kurstag,
		?int $anmeldungId,
		?int $wartelisteId,
		int $anmeldungen,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.geloescht',
			benutzer: $benutzer,
			kennung: $kennung,
			kurstag: $kurstag,
			anmeldungId: $anmeldungId,
			wartelisteId: $wartelisteId,
			plaetze: null,
			anmeldungen: $anmeldungen,
			grund: null,
		);
	}

	/**
	 * Ein halb gelaufener Loeschvorgang.
	 *
	 * Genau dieser Fall wird spaeter gesucht. Ein Protokoll, das nur Erfolge
	 * kennt, schweigt dann, wenn man es braucht.
	 *
	 * Jede steckengebliebene id steht in ihrer eigenen Spalte.
	 *
	 * Nicht immer die WARTELISTEN-Spalte: Die Loeschschleife bricht beim
	 * ersten Fehler nicht ab, sondern laeuft durch und sammelt ein. Die
	 * Anmeldung kann also genauso gut steckenbleiben wie die zweite
	 * Haelfte.
	 *
	 * Ein Formular ohne Art und ein doppelt angelegtes haben keine Spalte.
	 * Sie stehen in der Meldung an den Bediener, mit anklickbarer Adresse -
	 * das Protokoll haelt fest, DASS abgebrochen wurde, und die Kennung
	 * dazu.
	 */
	public static function loeschenAbgebrochen(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $kennung,
		string $kurstag,
		?int $anmeldungId,
		?int $wartelisteId,
		string $grund,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.loeschen_abgebrochen',
			benutzer: $benutzer,
			kennung: $kennung,
			kurstag: $kurstag,
			anmeldungId: $anmeldungId,
			wartelisteId: $wartelisteId,
			plaetze: null,
			anmeldungen: null,
			grund: $grund,
		);
	}

	/**
	 * Ein Kurs hat einen neuen Termin.
	 *
	 * BEIDE Kennungen stehen im Feld kennung, getrennt durch einen Pfeil.
	 * Nur eine liesse den Vorgang spaeter nicht mehr zuordnen: Wer sucht,
	 * kennt entweder den alten Titel oder den neuen, nie beide.
	 *
	 * Das Feld bleibt eine Spalte - eine zweite anzulegen hiesse, die
	 * Tabelle zu aendern, und die uebrigen Vorgangsarten haetten sie
	 * dauerhaft leer.
	 *
	 * "anmeldungen" ist eine ZAHL. Die Anmeldedaten selbst holt die App nie -
	 * beim Verschieben so wenig wie sonst.
	 */
	public static function verschoben(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $alteKennung,
		string $neueKennung,
		string $alterKurstag,
		string $neuerKurstag,
		int $anmeldungen,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.verschoben',
			benutzer: $benutzer,
			kennung: $alteKennung . ' → ' . $neueKennung,
			// Der NEUE Kurstag: Nach dem Vorgang gilt er, und danach wird
			// die Loeschfrist gerechnet.
			kurstag: $neuerKurstag,
			anmeldungId: null,
			wartelisteId: null,
			plaetze: null,
			anmeldungen: $anmeldungen,
			grund: 'vorher ' . $alterKurstag,
		);
	}

	/**
	 * Das Verschieben scheiterte, und der alte Termin steht wieder.
	 *
	 * Dieser Fall verlangt von niemandem etwas - er steht deshalb getrennt
	 * vom Abbruch.
	 */
	public static function verschiebungZurueckgerollt(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $alteKennung,
		string $neueKennung,
		string $grund,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.verschieben_zurueckgerollt',
			benutzer: $benutzer,
			kennung: $alteKennung . ' → ' . $neueKennung,
			kurstag: '',
			anmeldungId: null,
			wartelisteId: null,
			plaetze: null,
			anmeldungen: null,
			grund: $grund,
		);
	}

	/**
	 * Etwas steht noch auf dem neuen Termin. Nur dieser Fall verlangt, dass
	 * jemand etwas tut.
	 */
	public static function verschiebenAbgebrochen(
		DateTimeImmutable $jetzt,
		string $benutzer,
		string $alteKennung,
		string $neueKennung,
		string $stehengeblieben,
		string $grund,
	): self {
		return new self(
			zeitpunkt: self::inUtc($jetzt),
			vorgang: 'kurs.verschieben_abgebrochen',
			benutzer: $benutzer,
			kennung: $alteKennung . ' → ' . $neueKennung,
			kurstag: '',
			anmeldungId: null,
			wartelisteId: null,
			plaetze: null,
			anmeldungen: null,
			grund: 'stehengeblieben: ' . $stehengeblieben . ' — ' . $grund,
		);
	}

	private static function inUtc(DateTimeImmutable $zeitpunkt): DateTimeImmutable {
		return $zeitpunkt->setTimezone(new DateTimeZone('UTC'));
	}
}
