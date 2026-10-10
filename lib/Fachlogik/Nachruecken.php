<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use OCA\Radfahrschule\Formulare\Abgabe;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Protokoll\Protokoll;
use OCA\Radfahrschule\Protokoll\Vorgang;
use OCA\Radfahrschule\Sperre\Schreibsperre;

/**
 * Laesst Wartende in die Anmeldung nachruecken.
 *
 * Forms kann eine Abgabe nicht von einem Formular in ein anderes bewegen.
 * Die App reicht sie deshalb in der Anmeldung neu ein und loescht sie
 * danach in der Warteliste. In dieser Reihenfolge: Scheitert das Loeschen,
 * steht die Person in beiden Listen. Andersherum waere sie bei einem Fehler
 * ganz weg.
 *
 * Zurueckgerollt wird nichts. Mit der neuen Abgabe verschickt Forms seine
 * Bestaetigungsmail, und die laesst sich nicht zurueckholen. Der Lauf haelt
 * beim ersten Fehler an und sagt, was jetzt wo steht.
 *
 * Dieselbe Sperre wie beim Anlegen, Verschieben und Loeschen. So vergeben
 * nicht zwei Leute zugleich dieselben Plaetze, und niemand loescht den
 * Kurs, waehrend jemand nachrueckt.
 */
final readonly class Nachruecken {
	/**
	 * So kennzeichnet Forms beim Einreichen eine Antwort, die keine der
	 * Auswahlmoeglichkeiten ist. Beim Lesen liefert es nur den Text.
	 */
	private const EIGENE_ANTWORT = 'system-other-answer:';

	public function __construct(
		private Formulare $formulare,
		private Protokoll $protokoll,
		private Schreibsperre $sperre,
	) {
	}

	/**
	 * Die Anmeldung mit Platzzahl, Ablauf und Fragen. Die Liste der
	 * Formulare traegt das nicht alles, also wird sie einzeln geholt.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function anmeldung(Kurs $kurs): Formular {
		$haelfte = self::haelfte($kurs->anmeldung, 'Anmeldung');
		return $this->formulare->formularHolen($haelfte->id);
	}

	/**
	 * Die Warteliste. Wer sich zuerst eingetragen hat, steht vorn.
	 *
	 * Zwei Abgaben koennen dieselbe Sekunde tragen. Dann entscheidet die
	 * Nummer, Forms vergibt sie aufsteigend.
	 *
	 * @return list<Abgabe>
	 * @throws FormulareNichtErreichbar
	 */
	public function wartende(Kurs $kurs): array {
		$haelfte = self::haelfte($kurs->warteliste, 'Warteliste');
		$abgaben = $this->formulare->abgaben($haelfte->id);
		usort($abgaben, self::frueherZuerst(...));
		return $abgaben;
	}

	/** "8 von 10 Plaetzen sind belegt. Frei: 2." Ohne Platzzahl leer. */
	public function platzsatz(Formular $anmeldung): string {
		if ($anmeldung->platzzahl === null) {
			return '';
		}
		return sprintf('%d von %d Plätzen sind belegt. Frei: %d.',
			$anmeldung->abgaben, $anmeldung->platzzahl, (int)$anmeldung->freiePlaetze());
	}

	/**
	 * Warum gerade niemand nachruecken kann, oder null.
	 *
	 * Forms nimmt in ein abgelaufenes Formular keine Abgabe an. Nach dem
	 * Anmeldeschluss ist deshalb Schluss.
	 */
	public function hindernis(Formular $anmeldung, DateTimeImmutable $jetzt): ?string {
		if ($anmeldung->ablauf > 0 && $anmeldung->ablauf <= $jetzt->getTimestamp()) {
			return 'Der Anmeldeschluss ist vorbei. Nachrücken geht nicht mehr.';
		}
		if ($anmeldung->platzzahl === null) {
			return 'Die Anmeldung hat keine Platzzahl. Bitte in Forms eintragen.';
		}
		if ($anmeldung->freiePlaetze() === 0) {
			return 'Es ist kein Platz frei.';
		}
		return null;
	}

	/**
	 * @param list<int> $auswahl die Nummern der angekreuzten Abgaben
	 * @throws GeradeBeschaeftigt
	 * @throws FormulareNichtErreichbar
	 */
	public function lasseNachruecken(
		Kurs $kurs,
		array $auswahl,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Nachrueckergebnis {
		$this->sperre->nimm();
		try {
			return $this->rueckeGesperrt($kurs, $auswahl, $benutzer, $jetzt);
		} finally {
			$this->sperre->gib();
		}
	}

	/**
	 * @param list<int> $auswahl
	 * @throws FormulareNichtErreichbar
	 */
	private function rueckeGesperrt(
		Kurs $kurs,
		array $auswahl,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Nachrueckergebnis {
		// Unter der Sperre neu gelesen: Was die Seite gezeigt hat, kann
		// inzwischen ueberholt sein.
		$anmeldung = $this->anmeldung($kurs);
		$wartende = $this->wartende($kurs);
		$warteliste = self::haelfte($kurs->warteliste, 'Warteliste');
		$auswahl = array_values(array_unique($auswahl));

		$einwand = $this->einwand($anmeldung, $wartende, $auswahl, $jetzt);
		if ($einwand !== null) {
			return Nachrueckergebnis::abgewiesen($einwand);
		}

		$ausgewaehlt = self::ausgewaehlte($wartende, $auswahl);
		$ergebnis = $this->rueckeNach($anmeldung, $warteliste->id, $ausgewaehlt);

		$gescheitert = $ergebnis->stoerung === '' ? 0 : 1;
		$this->protokoll->schreibe(Vorgang::nachgerueckt(
			jetzt: $jetzt,
			benutzer: $benutzer,
			kennung: $kurs->kennung,
			kurstag: $kurs->kurstagIso(),
			anmeldungId: $anmeldung->id,
			wartelisteId: $warteliste->id,
			nachgerueckt: count($ergebnis->nachgerueckt),
			gescheitert: $gescheitert,
			uebrig: count($ergebnis->uebrig),
		));

		return $ergebnis;
	}

	/**
	 * Alles, was sich vor dem ersten Schreiben pruefen laesst.
	 *
	 * @param list<Abgabe> $wartende
	 * @param list<int> $auswahl
	 */
	private function einwand(
		Formular $anmeldung,
		array $wartende,
		array $auswahl,
		DateTimeImmutable $jetzt,
	): ?string {
		$hindernis = $this->hindernis($anmeldung, $jetzt);
		if ($hindernis !== null) {
			return $hindernis;
		}
		if ($auswahl === []) {
			return 'Es ist niemand ausgewählt.';
		}

		$ausgewaehlt = self::ausgewaehlte($wartende, $auswahl);
		if (count($ausgewaehlt) !== count($auswahl)) {
			return 'Die Warteliste hat sich geändert. Bitte neu auswählen.';
		}

		$frei = (int)$anmeldung->freiePlaetze();
		if (count($auswahl) > $frei) {
			return sprintf('%d ausgewählt, aber nur %d %s frei. Bitte neu auswählen.',
				count($auswahl), $frei, $frei === 1 ? 'Platz' : 'Plätze');
		}

		if (self::eineOhneFragenname($ausgewaehlt)) {
			return 'Die Warteliste hat eine Frage ohne technischen Namen. '
				. 'Die App kann ihre Antwort nicht übertragen. '
				. 'Bitte den Namen in Forms eintragen.';
		}

		foreach ($ausgewaehlt as $abgabe) {
			$fehlt = self::fehlendeFrage($abgabe, $anmeldung);
			if ($fehlt !== null) {
				return sprintf('Die Formulare passen nicht zusammen. '
					. 'Der Anmeldung fehlt die Frage „%s".', $fehlt);
			}
		}
		return null;
	}

	/**
	 * Eine Person nach der anderen. Beim ersten Fehler ist Schluss.
	 *
	 * @param list<Abgabe> $ausgewaehlt
	 */
	private function rueckeNach(Formular $anmeldung, int $wartelisteId, array $ausgewaehlt): Nachrueckergebnis {
		$nachgerueckt = [];

		foreach ($ausgewaehlt as $stelle => $abgabe) {
			$uebrig = self::namen(array_slice($ausgewaehlt, $stelle + 1));
			$antworten = self::uebersetze($abgabe, $anmeldung);

			try {
				$this->formulare->abgabeEinreichen($anmeldung->id, $antworten);
			} catch (FormulareNichtErreichbar $fehler) {
				$stoerung = self::stoerungBeimEinreichen($abgabe, $fehler);
				return new Nachrueckergebnis($nachgerueckt, $stoerung, $uebrig);
			}
			$nachgerueckt[] = $abgabe->name();

			try {
				$this->formulare->abgabeLoeschen($wartelisteId, $abgabe->id);
			} catch (FormulareNichtErreichbar) {
				$stoerung = sprintf('%s ist angemeldet, steht aber noch auf der Warteliste. '
					. 'Bitte dort von Hand löschen.', $abgabe->name());
				return new Nachrueckergebnis($nachgerueckt, $stoerung, $uebrig);
			}
		}

		return new Nachrueckergebnis($nachgerueckt);
	}

	/**
	 * Eine Ablehnung und eine ausgebliebene Antwort sind zwei Faelle. Nach
	 * einer Ablehnung steht fest, dass nichts geschrieben wurde. Ohne
	 * Antwort weiss es niemand, und die Meldung sagt das.
	 *
	 * Warum Forms ablehnt, sagt es nur in einem englischen Satz. Auf den
	 * verlaesst sich die App nicht.
	 */
	private static function stoerungBeimEinreichen(Abgabe $abgabe, FormulareNichtErreichbar $fehler): string {
		if ($fehler->istAblehnung()) {
			return sprintf('%s ist nicht nachgerückt. Forms hat die Anmeldung nicht angenommen. '
				. 'Wahrscheinlich ist der Platz inzwischen vergeben.', $abgabe->name());
		}
		return sprintf('Bei %s ist nicht sicher, ob die Anmeldung angekommen ist. '
			. 'Bitte in Forms nachsehen.', $abgabe->name());
	}

	/**
	 * Die Antworten einer Abgabe, wie die Anmeldung sie haben will.
	 *
	 * Gefunden wird jede Frage ueber ihren technischen Namen. Die
	 * Fragen-ID ist in jedem Formular eine andere.
	 *
	 * @return array<int, list<string>> Fragen-ID der Anmeldung zu den Werten
	 */
	private static function uebersetze(Abgabe $abgabe, Formular $anmeldung): array {
		$antworten = [];
		foreach ($anmeldung->fragen as $frage) {
			$texte = $abgabe->antworten[$frage->name] ?? [];
			if ($texte === []) {
				continue;
			}
			$antworten[$frage->id] = self::werteFuerFrage($frage, $texte);
		}
		return $antworten;
	}

	/**
	 * Eine Frage ohne Auswahl nimmt die Texte, wie sie sind.
	 *
	 * @param list<string> $texte
	 * @return list<string>
	 */
	private static function werteFuerFrage(Frage $frage, array $texte): array {
		if ($frage->auswahl === []) {
			return $texte;
		}
		return self::werte($frage, $texte);
	}

	/**
	 * Macht aus den gelesenen Texten einer Auswahlfrage das, was Forms beim
	 * Einreichen erwartet.
	 *
	 * Drei Faelle. Der Text ist eine Auswahlmoeglichkeit: ihre Nummer. Die
	 * Frage hat nur ein Kaestchen: Es wird angekreuzt, auch wenn sein Text
	 * in der Warteliste anders lautet. Sonst hat die Person die Antwort
	 * selbst getippt.
	 *
	 * @param list<string> $texte
	 * @return list<string>
	 */
	private static function werte(Frage $frage, array $texte): array {
		$werte = [];
		foreach ($texte as $text) {
			$werte[] = self::wertFuerText($frage, $text);
		}
		return $werte;
	}

	private static function wertFuerText(Frage $frage, string $text): string {
		$nummer = array_search($text, $frage->auswahl, true);
		if ($nummer !== false) {
			return (string)$nummer;
		}
		if (count($frage->auswahl) === 1) {
			return (string)array_key_first($frage->auswahl);
		}
		return self::EIGENE_ANTWORT . $text;
	}

	/**
	 * Ob eine Abgabe eine Antwort traegt, die sich nicht zuordnen laesst.
	 *
	 * @param list<Abgabe> $abgaben
	 */
	private static function eineOhneFragenname(array $abgaben): bool {
		foreach ($abgaben as $abgabe) {
			if ($abgabe->antwortenOhneFragenname > 0) {
				return true;
			}
		}
		return false;
	}

	/** Der Name einer beantworteten Frage, die es in der Anmeldung nicht gibt, oder null. */
	private static function fehlendeFrage(Abgabe $abgabe, Formular $anmeldung): ?string {
		foreach (array_keys($abgabe->antworten) as $name) {
			if ($anmeldung->frageMitNamen($name) === null) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Die angekreuzten Abgaben, in der Reihenfolge der Warteliste.
	 *
	 * @param list<Abgabe> $wartende
	 * @param list<int> $auswahl
	 * @return list<Abgabe>
	 */
	private static function ausgewaehlte(array $wartende, array $auswahl): array {
		$ausgewaehlt = [];
		foreach ($wartende as $abgabe) {
			if (in_array($abgabe->id, $auswahl, true)) {
				$ausgewaehlt[] = $abgabe;
			}
		}
		return $ausgewaehlt;
	}

	/**
	 * @param list<Abgabe> $abgaben
	 * @return list<string>
	 */
	private static function namen(array $abgaben): array {
		$namen = [];
		foreach ($abgaben as $abgabe) {
			$namen[] = $abgabe->name();
		}
		return $namen;
	}

	private static function frueherZuerst(Abgabe $eine, Abgabe $andere): int {
		return [$eine->zeitpunkt, $eine->id] <=> [$andere->zeitpunkt, $andere->id];
	}

	/** @throws FormulareNichtErreichbar */
	private static function haelfte(?Formular $formular, string $name): Formular {
		return $formular ?? throw new FormulareNichtErreichbar(
			sprintf('Zu diesem Kurs fehlt die %s.', $name));
	}
}
