<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Controller;

use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Settings\Einstellungsbereich;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Nimmt die Zugangsdaten und die Angaben des Betreibers von der
 * Admin-Einstellungsseite entgegen.
 *
 * Ohne diesen Weg gaebe es keinen: Auf einer gehosteten Nextcloud gibt es
 * keine Kommandozeile, und die App braucht Adresse, Konto, Passwort und
 * Gruppe, um ueberhaupt etwas anzuzeigen. Ein "occ config:app:set" steht
 * dort nicht zur Verfuegung.
 *
 * KEIN NoAdminRequired: Ohne dieses Attribut verlangt Nextclouds
 * SecurityMiddleware ein Admin-Konto und wirft sonst NotAdminException.
 * Die Pruefung steht deshalb nicht im Code - sie steht in dem, was hier
 * fehlt.
 *
 * Ebenso kein NoCSRFRequired: Die Pruefung des requesttoken greift.
 */
class EinstellungenController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly Zugangsdaten $zugangsdaten,
		private readonly Betreiberangaben $betreiberangaben,
		private readonly IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[FrontpageRoute(verb: 'POST', url: '/einstellungen')]
	public function speichere(): RedirectResponse {
		$this->zugangsdaten->setzeBasisUrl($this->feld('basisUrl'));
		$this->zugangsdaten->setzeBenutzer($this->feld('benutzer'));
		$this->zugangsdaten->setzeFreigabeGruppe($this->feld('freigabeGruppe'));

		// Ein leeres Feld laesst das alte Passwort stehen. Die Seite gibt es
		// nie zurueck, also kaeme es bei jedem Speichern leer wieder an - und
		// wuerde den Wert loeschen, den niemand anfassen wollte.
		$passwort = $this->feld('appPasswort');
		if ($passwort !== '') {
			$this->zugangsdaten->setzeAppPasswort($passwort);
		}

		$this->betreiberangaben->setzeName($this->feld('name'));
		$this->betreiberangaben->setzeKursart($this->feld('kursart'));
		$this->betreiberangaben->setzeZeitzone($this->feld('zeitzone'));
		$this->betreiberangaben->setzeTerminportalHinweis($this->feld('terminportalHinweis'));
		$this->betreiberangaben->setzeAntwortadresse($this->feld('antwortadresse'));
		$this->betreiberangaben->setzeAbsageBetreff($this->feld('absageBetreff'));
		$this->betreiberangaben->setzeAbsageTextAngemeldete($this->feld('absageTextAngemeldete'));
		$this->betreiberangaben->setzeAbsageTextWartende($this->feld('absageTextWartende'));
		$this->betreiberangaben->setzeVerschiebungBetreff($this->feld('verschiebungBetreff'));
		$this->betreiberangaben->setzeVerschiebungTextAngemeldete($this->feld('verschiebungTextAngemeldete'));
		$this->betreiberangaben->setzeVerschiebungTextWartende($this->feld('verschiebungTextWartende'));

		// Ein unbrauchbarer Wert wird zur Null. Betreiberangaben faellt beim
		// Lesen auf die Vorbelegung zurueck - null Tage hiesse "sofort
		// loeschen", und das hat niemand gewollt, der das Feld leer laesst.
		$this->betreiberangaben->setzeAufbewahrungTage((int)$this->feld('aufbewahrungTage'));

		// In den EIGENEN Bereich, nicht nach "additional". Zeigte der
		// Sprung woandershin, landete man nach dem Speichern auf einer
		// fremden Seite ohne seine Einstellungen - und nichts meldete
		// einen Fehler.
		return new RedirectResponse($this->urlGenerator->linkToRoute(
			'settings.AdminSettings.index',
			['section' => Einstellungsbereich::KENNUNG]));
	}

	/** Randleerzeichen sind bei jedem dieser Felder ein Tippfehler. */
	private function feld(string $name): string {
		return trim((string)$this->request->getParam($name, ''));
	}
}
