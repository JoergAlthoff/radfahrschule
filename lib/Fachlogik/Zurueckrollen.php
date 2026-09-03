<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use Throwable;
use OCA\Radfahrschule\Formulare\Formulare;

/**
 * Loescht, was ein gescheiterter Lauf angelegt hat.
 *
 * Das Zurueckrollen macht aus drei moeglichen Ausgaengen zwei: Der Kurs
 * steht, oder es ist nichts entstanden. Der dritte - HALB entstanden -
 * verlangte Handarbeit und die Kontrolle, ob sie erledigt wurde.
 *
 * Es reicht, die Klone zu loeschen. Titel, Termin, Ablaufwerte und Freigaben
 * sind keine eigenen Dinge, sie haengen am Formular.
 */
final readonly class Zurueckrollen {
	public function __construct(
		private Formulare $formulare,
		private string $basisUrl,
	) {
	}

	/**
	 * Raeumt die Klone weg und liefert die, bei denen das nicht gelang.
	 *
	 * Geloescht wird rueckwaerts, also das zuletzt Angelegte zuerst. Das
	 * haelt die Ordnung der Meldung: Was zuletzt entstand, steht dem Fehler
	 * am naechsten.
	 *
	 * Geloescht wird mit derselben Methode wie beim Loeschen eines Kurses -
	 * kein eigener Pfad nur fuer den Fehlerfall.
	 *
	 * @param list<ErzeugtesFormular> $erzeugt
	 * @return list<ErzeugtesFormular>
	 */
	public function raeumeAuf(array $erzeugt): array {
		$stehengeblieben = [];

		foreach (array_reverse($erzeugt) as $formular) {
			try {
				$this->formulare->formularLoeschen($formular->id);
			} catch (Throwable) {
				// Ein Fehlschlag darf die uebrigen nicht verhindern - sonst
				// bliebe mehr stehen als noetig.
				$stehengeblieben[] = $formular;
			}
		}

		return $stehengeblieben;
	}

	/**
	 * Sagt, was noch in Nextcloud steht - mit Link, nicht mit Nummer.
	 *
	 * @param list<ErzeugtesFormular> $stehengeblieben
	 */
	public function hinweis(array $stehengeblieben): string {
		$zeilen = ['Das Aufräumen misslang. Diese Formulare stehen noch in Nextcloud:'];

		foreach ($stehengeblieben as $formular) {
			$zeilen[] = '';
			$zeilen[] = '  ' . $formular->beschriftung;
			$zeilen[] = '  ' . $this->editorAdresse($formular->hash);
		}

		$zeilen[] = '';
		$zeilen[] = 'Bitte dort löschen und danach erneut versuchen.';

		return implode("\n", $zeilen);
	}

	/**
	 * Die anklickbare Adresse eines Formulars.
	 *
	 * Eine Formular-ID ist in Nextcloud NIRGENDS zu sehen: Die Liste zeigt
	 * Titel, die Editor-Adresse zeigt einen Hash. Eine Zeile mit einer
	 * Nummer gibt dem Menschen davor nichts, womit er das Formular
	 * wiederfaende.
	 *
	 * Der Hash hier ist der EDITOR-Hash (16 Zeichen), nicht der oeffentliche
	 * Share-Hash (24). Genau der ist hier richtig: Gemeint ist die Seite zum
	 * Bearbeiten und Loeschen, nicht die zum Anmelden.
	 */
	private function editorAdresse(string $hash): string {
		return rtrim($this->basisUrl, '/') . '/apps/forms/' . $hash . '/edit';
	}
}
