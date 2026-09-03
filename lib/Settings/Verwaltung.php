<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Settings;

use DateTimeZone;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class Verwaltung implements ISettings {
	public function __construct(
		private Zugangsdaten $zugangsdaten,
		private Betreiberangaben $betreiberangaben,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse('radfahrschule', 'einstellungen', [
			'basisUrl' => $this->zugangsdaten->basisUrl(),
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

			// Eine Auswahlliste statt eines Feldes: Ein Tippfehler fiele
			// sonst still auf die Vorbelegung zurueck, und niemand saehe,
			// warum die Kurstage um einen Tag danebenliegen.
			'zeitzonen' => DateTimeZone::listIdentifiers(),
		]);
	}

	public function getSection(): string {
		return Einstellungsbereich::KENNUNG;
	}

	public function getPriority(): int {
		return 50;
	}
}
