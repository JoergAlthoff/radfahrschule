<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use Throwable;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Formulare;

/**
 * Stellt die alten Werte eines gescheiterten Verschiebens wieder her.
 *
 * Das Zurueckschreiben macht aus drei moeglichen Ausgaengen zwei: Der Kurs
 * steht auf dem neuen Termin, oder er steht auf dem alten. Der dritte - halb
 * verschoben - liesse ein Formular zurueck, dessen Titel und Text sich
 * widersprechen. Wer es dann liest, glaubt dem einen oder dem anderen.
 */
final readonly class Zurueckschreiben {
	public function __construct(
		private Formulare $formulare,
		private string $basisUrl,
	) {
	}

	/**
	 * Stellt jedes geaenderte Formular wieder her und liefert die, bei denen
	 * das nicht gelang.
	 *
	 * Rueckwaerts, also das zuletzt Geaenderte zuerst: Was dem Fehler am
	 * naechsten steht, wird zuerst geheilt.
	 *
	 * Die Liste fuellt allein die Schreibkette; nie eine Formularliste, denn
	 * eine id von woanders koennte einen fremden Kurs treffen.
	 *
	 * @param list<AlterStand> $geaendert
	 * @return list<AlterStand>
	 */
	public function stelleWiederHer(array $geaendert): array {
		$stehengeblieben = [];

		foreach (array_reverse($geaendert) as $stand) {
			if (!$this->stelleEinesWiederHer($stand)) {
				$stehengeblieben[] = $stand;
			}
		}

		return $stehengeblieben;
	}

	/**
	 * Bringt ein Formular auf seinen alten Stand zurueck. Gibt zurueck, ob
	 * es danach dort steht.
	 *
	 * Entschieden wird am IST-STAND, nicht an einem Merker aus der
	 * Schreibkette. Der Merker konnte beides nicht auseinanderhalten: Ein
	 * Aufruf, den Forms gar nicht erst annahm, hat nichts geaendert - ein
	 * Aufruf, dessen Antwort verloren ging, sehr wohl. Fuer den Anrufer
	 * sieht beides gleich aus.
	 *
	 * Daraus folgt beides zugleich: Was nie geschrieben wurde, wird auch
	 * nicht angefasst, und was geschrieben wurde, geht zurueck - auch wenn
	 * die Schreibkette es nicht mehr vermerken konnte.
	 */
	private function stelleEinesWiederHer(AlterStand $stand): bool {
		try {
			$jetziger = $this->formulare->formularHolen($stand->id);
		} catch (Throwable) {
			// Ohne den Ist-Stand laesst sich nichts sagen. Im Zweifel gilt
			// das Formular als stehengeblieben.
			return false;
		}

		$gelungen = true;

		if ($jetziger->titel !== $stand->titel) {
			try {
				$this->formulare->formularAendern($stand->id, [
					'title' => $stand->titel,
					'expires' => $stand->ablauf,
				]);
			} catch (Throwable) {
				$gelungen = false;
			}
		}

		// Unabhaengig vom Titel versucht: Misslingt der, kann die
		// Terminzeile trotzdem noch zurueckgehen.
		if ($this->fragetextWeichtAb($jetziger, $stand)) {
			try {
				$this->formulare->frageAendern($stand->id, $stand->frageId,
					['description' => $stand->fragetext]);
			} catch (Throwable) {
				$gelungen = false;
			}
		}

		return $gelungen;
	}

	/**
	 * Ist die Frage gar nicht mehr da, gibt es nichts zurueckzuschreiben -
	 * und ein Aufruf auf eine Frage, die es nicht gibt, scheiterte nur.
	 */
	private function fragetextWeichtAb(Formular $jetziger, AlterStand $stand): bool {
		foreach ($jetziger->fragen as $frage) {
			if ($frage->id === $stand->frageId) {
				return $frage->beschreibung !== $stand->fragetext;
			}
		}
		return false;
	}

	/**
	 * Sagt, welche Formulare noch auf dem neuen Termin stehen - mit Link,
	 * nicht mit Nummer.
	 *
	 * Eine Formular-id ist in Nextcloud nirgends zu sehen: Die Liste zeigt
	 * Titel, die Editor-Adresse zeigt einen Hash.
	 *
	 * @param list<AlterStand> $stehengeblieben
	 */
	public function hinweis(array $stehengeblieben): string {
		$zeilen = ['Das Zurückschreiben misslang. Diese Formulare tragen noch den neuen Termin:'];

		foreach ($stehengeblieben as $stand) {
			$zeilen[] = '';
			$zeilen[] = '  ' . $stand->beschriftung;
			$zeilen[] = '  ' . $this->editorAdresse($stand->hash);
		}

		$zeilen[] = '';
		$zeilen[] = 'Bitte dort den alten Termin wieder eintragen.';

		return implode("\n", $zeilen);
	}

	private function editorAdresse(string $hash): string {
		return rtrim($this->basisUrl, '/') . '/apps/forms/' . $hash . '/edit';
	}
}
