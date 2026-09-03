<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;

/**
 * Baut und zerlegt die Titel der Formulare eines Kurses.
 *
 * Der Anfang jedes Anmeldetitels kommt aus den Betreiberangaben. Deshalb ist
 * das ein Objekt und keine statische Klasse - eine statische Methode kann
 * keine Einstellung lesen.
 *
 * Titel ist das Ergebnis des Zerlegens: Art und Kennung. Diese Klasse ist
 * die Fabrik dazu.
 */
readonly class Titelmuster {
	public function __construct(private Betreiberangaben $betreiberangaben) {
	}

	/**
	 * Zerlegt einen Formulartitel in Art und Kennung.
	 *
	 * Die Vorlage wird zuerst geprueft, und darauf kommt es an: Traegt der
	 * Betreiber "VORLAGE" als Namen ein, faengt jeder Vorlagentitel auch mit
	 * seinem Praefix an. Gewaenne die Anmeldung, verschwaenden die Vorlagen
	 * aus der Auswahl, und niemand koennte mehr einen Kurs anlegen.
	 */
	public function zerlege(string $titel): Titel {
		if (str_starts_with($titel, Titel::PRAEFIX_VORLAGE)) {
			return Titel::vorlage();
		}

		$kennung = $this->ohnePraefix($titel, $this->praefixAnmeldung());
		if ($kennung !== null) {
			return Titel::mit(Formularart::Anmeldung, $kennung);
		}

		$kennung = $this->ohnePraefix($titel, $this->praefixWarteliste());
		if ($kennung !== null) {
			return Titel::mit(Formularart::Warteliste, $kennung);
		}

		return Titel::mit(Formularart::Unbekannt, $titel);
	}

	public function fuerAnmeldung(DateTimeImmutable $von, DateTimeImmutable $bis): string {
		return $this->praefixAnmeldung() . $this->kennung($von, $bis);
	}

	public function fuerWarteliste(DateTimeImmutable $von, DateTimeImmutable $bis): string {
		return $this->praefixWarteliste() . $this->kennung($von, $bis);
	}

	/**
	 * Der Anfang jedes Anmeldetitels, aus den Betreiberangaben.
	 *
	 * Verschiebeplan baut daraus die neuen Titel, ohne den Termin noch
	 * einmal zu rechnen.
	 */
	public function praefixAnmeldung(): string {
		return $this->betreiberangaben->titelPraefix();
	}

	/**
	 * Der Anfang jedes Wartelistentitels.
	 *
	 * Er haengt nicht am Betreiber: "Warteliste" sagt die Rolle im Kurs,
	 * nicht wer den Kurs veranstaltet. Deshalb ist er eine Konstante.
	 */
	public function praefixWarteliste(): string {
		return Titel::PRAEFIX_WARTELISTE;
	}

	/** Der gemeinsame Rest beider Titel: Kursart und Termin. */
	private function kennung(DateTimeImmutable $von, DateTimeImmutable $bis): string {
		return $this->betreiberangaben->kursart() . Termin::kurz($von, $bis);
	}

	/**
	 * Der Rest hinter dem Praefix, oder null.
	 *
	 * Ein leerer Praefix passt nie. Das ist der Grund fuer die erste
	 * Bedingung: str_starts_with($titel, '') ist immer wahr. Ohne sie waere
	 * jedes Formular der Instanz eine Anmeldung, sobald der Name fehlt -
	 * auch eine Mitgliederbefragung.
	 */
	private function ohnePraefix(string $titel, string $praefix): ?string {
		if ($praefix === '' || !str_starts_with($titel, $praefix)) {
			return null;
		}
		return substr($titel, strlen($praefix));
	}
}
