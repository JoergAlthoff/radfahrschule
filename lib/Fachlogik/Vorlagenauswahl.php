<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use OCA\Radfahrschule\Formulare\Formular;

/**
 * Ein Auswahlfeld: die Zeilen und die Frage, ob "bitte waehlen" davorsteht.
 *
 * Anzeige und Pruefung haengen bewusst am selben Typ. Was im Feld steht, ist
 * damit dasselbe, was enthaelt durchlaesst - zwei getrennte Listen koennten
 * auseinanderlaufen. Die Auswahl im Browser reicht als Schutz nicht: Das
 * Feld ist veraenderbar.
 */
final class Vorlagenauswahl {
	/**
	 * Wofuer eine Vorlage gedacht ist, steht hinter dem Praefix im Titel.
	 * Der Trenner gehoert dazu - sonst zaehlte "VORLAGE Anmeldungsbogen" als
	 * Anmeldevorlage.
	 *
	 * Hier steht ein gewoehnlicher Bindestrich, kein Gedankenstrich. Diese
	 * beiden Titel tippt ein Mensch von Hand in Nextcloud ein, und den
	 * Gedankenstrich hat nicht jede Tastatur. Die Kurstitel, die die App
	 * SELBST erzeugt, tragen weiter den Gedankenstrich - dort tippt niemand.
	 */
	public const PRAEFIX_ANMELDUNG = Titel::PRAEFIX_VORLAGE . 'Anmeldung - ';
	public const PRAEFIX_WARTELISTE = Titel::PRAEFIX_VORLAGE . 'Warteliste - ';

	/**
	 * Die aeltere Schreibweise, mit Gedankenstrich statt Bindestrich.
	 *
	 * Sie wird weiter erkannt, aber nicht mehr empfohlen. Bestehende
	 * Vorlagen muessen deshalb nicht umbenannt werden - auch dann nicht,
	 * wenn noch etwas anderes auf denselben Formularen arbeitet.
	 */
	private const PRAEFIX_ANMELDUNG_ALT = Titel::PRAEFIX_VORLAGE . 'Anmeldung — ';
	private const PRAEFIX_WARTELISTE_ALT = Titel::PRAEFIX_VORLAGE . 'Warteliste — ';

	/** @param list<array{id:int, titel:string, gewaehlt:bool}> $optionen */
	private function __construct(
		private readonly array $optionen,
		private readonly bool $freieWahl,
	) {
	}

	/**
	 * Baut ein Auswahlfeld aus den Vorlagen EINER Art.
	 *
	 * @param list<Formular> $formulare
	 */
	public static function ausFormularen(array $formulare, Formularart $art): self {
		$muster = [];
		foreach ($formulare as $formular) {
			// Die Praefix-Pruefung steht getrennt von der Art: Ohne sie
			// kaeme bei Formularart::Unbekannt jeder laufende Kurs zurueck.
			if (!str_starts_with($formular->titel, Titel::PRAEFIX_VORLAGE)) {
				continue;
			}
			if (self::artAus($formular->titel) === $art) {
				$muster[] = $formular;
			}
		}

		// Steht genau eine zur Wahl, ist sie gesetzt und "bitte waehlen"
		// entfaellt. Das traegt nur, weil jedes Feld ausschliesslich seine
		// Art zeigt - sonst stuende dieselbe Vorlage in beiden Feldern.
		//
		// Diese Zahl ist zugleich die Warnung. Bleibt nach einem
		// gescheiterten Lauf ein Klon der Vorlage stehen, traegt er weiter
		// das VORLAGE-Praefix und steht damit hier. Aussortiert wird er
		// NICHT: Den Zusatz hinter seinem Titel vergibt Nextcloud und
		// uebersetzt ihn - "- Kopie" gilt nur auf Deutsch. Ein Filter darauf
		// griffe in einer Sprache und liesse die Kopie sonst still durch,
		// und dann waere sie im Feld nicht von der echten Vorlage zu
		// unterscheiden.
		//
		// Zwei Eintraege heben deshalb die Vorauswahl auf. Wer anlegen will,
		// sieht beide Titel und entscheidet selbst - der Titel eines
		// halbfertigen Klons ist einem Menschen anzusehen, einem
		// Textvergleich nicht.
		$nurEine = count($muster) === 1;

		$optionen = [];
		foreach ($muster as $formular) {
			$optionen[] = [
				'id' => $formular->id,
				'titel' => $formular->titel,
				'gewaehlt' => $nurEine,
			];
		}

		return new self($optionen, !$nurEine);
	}

	/**
	 * Liest aus dem Titel, wofuer eine Vorlage gedacht ist. Nennt der Titel
	 * die Art nicht, ist sie Unbekannt - eine solche Vorlage steht in keinem
	 * der beiden Felder.
	 */
	private static function artAus(string $titel): Formularart {
		if (str_starts_with($titel, self::PRAEFIX_ANMELDUNG)
			|| str_starts_with($titel, self::PRAEFIX_ANMELDUNG_ALT)) {
			return Formularart::Anmeldung;
		}
		if (str_starts_with($titel, self::PRAEFIX_WARTELISTE)
			|| str_starts_with($titel, self::PRAEFIX_WARTELISTE_ALT)) {
			return Formularart::Warteliste;
		}
		return Formularart::Unbekannt;
	}

	/** @return list<array{id:int, titel:string, gewaehlt:bool}> */
	public function optionen(): array {
		return $this->optionen;
	}

	public function freieWahl(): bool {
		return $this->freieWahl;
	}

	public function istLeer(): bool {
		return $this->optionen === [];
	}

	/**
	 * Prueft eine gewaehlte Formular-ID gegen DIESES Feld.
	 *
	 * Gefragt wird das Feld, in dem die ID stand. Wer beide Felder gegen
	 * dieselbe Auswahl prueft, laesst die Vertauschung von Anmeldung und
	 * Warteliste durch.
	 */
	public function enthaelt(int $formularId): bool {
		foreach ($this->optionen as $option) {
			if ($option['id'] === $formularId) {
				return true;
			}
		}
		return false;
	}
}
