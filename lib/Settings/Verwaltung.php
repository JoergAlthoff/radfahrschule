<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Settings;

use DateTimeZone;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Mail\IEmailValidator;
use OCP\Settings\ISettings;

class Verwaltung implements ISettings {
	public function __construct(
		private Zugangsdaten $zugangsdaten,
		private Betreiberangaben $betreiberangaben,
		private Formulare $formulare,
		private IEmailValidator $emailValidator,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse('radfahrschule', 'einstellungen', [
			'basisUrl' => $this->zugangsdaten->basisUrl(),
			// Ohne https gehen Dienstkonto und App-Passwort im Klartext
			// hinaus. Gewarnt, nicht gesperrt: Eine Testinstanz im eigenen
			// Netz hat oft kein Zertifikat.
			'adresseOhneHttps' => $this->adresseOhneHttps(),
			'benutzer' => $this->zugangsdaten->benutzer(),
			// Das Passwort wird NIE zurueckgegeben, nur ob eines gesetzt
			// ist. Nextcloud haelt es verschluesselt.
			'passwortGesetzt' => $this->zugangsdaten->appPasswort() !== '',
			'freigabeGruppe' => $this->zugangsdaten->freigabeGruppe(),

			// Der Name OHNE Trenner: Das Feld traegt nur ihn, den Rest
			// haengt titelPraefix() an.
			'name' => $this->betreiberangaben->name(),
			'kursart' => $this->betreiberangaben->kursartRoh(),
			'aufbewahrungTage' => $this->betreiberangaben->aufbewahrungTage(),
			'zeitzone' => $this->betreiberangaben->zeitzone(),
			'terminportalHinweis' => $this->betreiberangaben->terminportalHinweis(),
			'antwortadresse' => $this->betreiberangaben->antwortadresse(),
			// Eine ungueltige Adresse laesst der Versand weg. Ohne diese
			// Warnung landeten Antworten still bei der Absenderadresse der
			// Instanz.
			'antwortadresseUngueltig' => $this->antwortadresseUngueltig(),
			'absageBetreff' => $this->betreiberangaben->absageBetreff(),
			'absageTextAngemeldete' => $this->betreiberangaben->absageTextAngemeldete(),
			'absageTextWartende' => $this->betreiberangaben->absageTextWartende(),
			'verschiebungBetreff' => $this->betreiberangaben->verschiebungBetreff(),
			'verschiebungTextAngemeldete' => $this->betreiberangaben->verschiebungTextAngemeldete(),
			'verschiebungTextWartende' => $this->betreiberangaben->verschiebungTextWartende(),

			// Eine Auswahlliste statt eines Feldes: Ein Tippfehler fiele
			// sonst still auf die Vorbelegung zurueck, und niemand saehe,
			// warum die Kurstage um einen Tag danebenliegen.
			'zeitzonen' => DateTimeZone::listIdentifiers(),

			// Ob die eingetragenen Zugangsdaten wirklich tragen. Ohne diese
			// Auskunft zeigt sich ein Tippfehler erst daran, dass die
			// Kursliste leer bleibt - auf einer anderen Seite, und ohne zu
			// sagen, welches der vier Felder schuld ist.
			'zugangsfehler' => $this->zugangsfehler(),
			'zugangGeprueft' => $this->zugangsdaten->sindVollstaendig(),
		]);
	}

	/**
	 * Probiert den Zugang und nennt den Grund, wenn er nicht traegt.
	 *
	 * Ein LESENDER Aufruf, und der billigste, den es gibt: die Liste der
	 * eigenen Formulare. Er legt nichts an und aendert nichts.
	 *
	 * Sind die Felder noch leer, geht gar kein Aufruf hinaus. Bei einer
	 * frisch installierten App ist das der normale Zustand, und eine
	 * Warnung waere dort keine Auskunft - dass die Felder leer sind, sieht
	 * man daneben selbst.
	 *
	 * Weitergegeben wird die Meldung der Anbindung, nicht eine eigene: Die
	 * dort unterscheidet nach HTTP-Status, ob Konto, Passwort oder Adresse
	 * gemeint ist. Ein eigener Satz hier verloere das wieder.
	 *
	 * Es kostet einen Aufruf, sooft die Seite geoeffnet wird. Sie wird
	 * selten geoeffnet.
	 */
	private function zugangsfehler(): string {
		if (!$this->zugangsdaten->sindVollstaendig()) {
			return '';
		}

		try {
			$this->formulare->alleEigenen();
			return '';
		} catch (FormulareNichtErreichbar $fehler) {
			return $fehler->getMessage();
		}
	}

	/** Das Schema kennt keine Gross- und Kleinschreibung. */
	private function adresseOhneHttps(): bool {
		$adresse = strtolower($this->zugangsdaten->basisUrl());
		return $adresse !== '' && !str_starts_with($adresse, 'https://');
	}

	private function antwortadresseUngueltig(): bool {
		$adresse = $this->betreiberangaben->antwortadresse();
		return $adresse !== '' && !$this->emailValidator->isValid($adresse);
	}

	public function getSection(): string {
		return Einstellungsbereich::KENNUNG;
	}

	public function getPriority(): int {
		return 50;
	}
}
