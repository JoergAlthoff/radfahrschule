<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use Throwable;
use OCA\Radfahrschule\Formulare\Formulare;

/**
 * Schreibt einem Kurs den neuen Termin: je Formular Titel samt Ablauf, dann
 * die Terminzeile. Danach wird zurueckgelesen.
 *
 * Erst lesen, dann schreiben - und das ist der Kern: Beim Einlesen faellt
 * auf, wenn die Terminzeile fehlt oder mehrfach vorkommt, BEVOR irgendwo
 * geschrieben wurde. Eine Meldung nach dem ersten Schreibaufruf waere zu
 * spaet: Der Kurs stuende dann unter neuem Namen mit altem Text da.
 */
final class Verschiebekette {
	private const BEDINGUNGSFRAGE = 'teilnahmebedingungen';

	/** @var list<AlterStand> der Stand jedes Formulars vor dem Schreiben */
	private array $gelesen = [];

	/**
	 * @var list<AlterStand> die, bei denen ein Schreibaufruf HINAUSGING
	 *
	 * Nicht "gelang": Ein Aufruf, der Forms erreicht hat und dessen Antwort
	 * verloren ging, hat dort gewirkt. Wer ihn nicht vermerkt, laesst genau
	 * das Formular aus, das gerade geaendert wurde. Ein Formular doppelt
	 * zurueckzuschreiben ist dagegen harmlos - es bekommt denselben alten
	 * Stand.
	 */
	private array $geaendert = [];

	public function __construct(
		private readonly Formulare $formulare,
		private readonly Verschiebeplan $plan,
		private readonly Kurs $kurs,
		private readonly Titelmuster $titelmuster,
	) {
	}

	/**
	 * Holt jedes Formular des Kurses und merkt sich seinen Stand.
	 *
	 * Der neue Text wird hier nur PROBEWEISE gebaut. Gebraucht wird er erst
	 * beim Schreiben; hier zaehlt allein, dass es geht.
	 *
	 * @throws VerschiebenFehlgeschlagen
	 */
	public function leseAllesEin(): void {
		foreach ($this->kurs->zeilen() as $zeile) {
			try {
				$gelesen = $this->formulare->formularHolen($zeile->formular->id);
			} catch (Throwable $fehler) {
				throw new VerschiebenFehlgeschlagen(sprintf(
					'Das Formular „%s" ließ sich nicht lesen: %s',
					$zeile->beschriftung, $fehler->getMessage()), previous: $fehler);
			}

			// Die Art muss am Titel ablesbar sein. neuerTitelFuer kennt nur
			// "Warteliste oder sonst Anmeldung" - ein Formular ohne Praefix
			// bekaeme also den Anmeldungstitel samt Anmeldeschluss, obwohl
			// es womoeglich die umbenannte Warteliste ist. Danach traegt der
			// Kurs zweimal denselben Titel.
			$art = $this->titelmuster->zerlege($gelesen->titel)->art;
			if ($art !== Formularart::Anmeldung && $art !== Formularart::Warteliste) {
				throw new VerschiebenFehlgeschlagen(sprintf(
					'Am Titel „%s" ist nicht zu erkennen, ob es die Anmeldung '
					. 'oder die Warteliste ist. Bitte in Nextcloud den Titel '
					. 'richtigstellen, dann noch einmal versuchen.',
					$gelesen->titel));
			}

			$frage = $gelesen->frageMitNamen(self::BEDINGUNGSFRAGE);
			if ($frage === null) {
				throw new VerschiebenFehlgeschlagen(sprintf(
					'Dem Formular „%s" fehlt die Frage „%s".',
					$zeile->beschriftung, self::BEDINGUNGSFRAGE));
			}

			try {
				Textersetzung::terminZeile($frage->beschreibung, $this->plan->termin);
			} catch (Throwable $fehler) {
				throw new VerschiebenFehlgeschlagen(sprintf(
					'Im Formular „%s": %s', $zeile->beschriftung, $fehler->getMessage()),
					previous: $fehler);
			}

			$this->gelesen[] = new AlterStand(
				beschriftung: $zeile->beschriftung,
				id: $gelesen->id,
				hash: $gelesen->hash,
				titel: $gelesen->titel,
				ablauf: $gelesen->ablauf,
				frageId: $frage->id,
				fragetext: $frage->beschreibung,
			);
		}
	}

	/**
	 * Fuehrt die Schreibkette aus und liest danach zurueck.
	 *
	 * Die Warteliste steht zuerst. Ein Zwang besteht dafuer nicht - die
	 * Reihenfolge folgt dem Anlegen, damit beide Ketten gleich zu lesen sind.
	 *
	 * @throws VerschiebenFehlgeschlagen
	 */
	public function schreibeAlles(): void {
		$nummer = 0;

		foreach (array_reverse($this->gelesen) as $stand) {
			$nummer++;
			// Vermerkt wird VOR dem Aufruf. Danach waere es zu spaet: Reisst
			// die Antwort ab, hat Forms trotzdem geschrieben.
			$this->geaendert[] = $stand;
			try {
				$this->formulare->formularAendern($stand->id, [
					'title' => $this->neuerTitelFuer($stand),
					'expires' => $this->neuerAblaufFuer($stand),
				]);
			} catch (Throwable $fehler) {
				throw $this->scheitert($nummer, $stand, $fehler->getMessage(), $fehler);
			}

			// Ohne try: leseAllesEin hat denselben Aufruf mit demselben Text
			// schon probeweise gemacht und einen Fehler dort gemeldet. Kaeme
			// hier doch einer, waere die Annahme falsch, auf der die ganze
			// Reihenfolge beruht - und ein stiller Fang verdeckte genau das.
			$neuerText = Textersetzung::terminZeile($stand->fragetext, $this->plan->termin);

			$nummer++;
			try {
				$this->formulare->frageAendern($stand->id, $stand->frageId,
					['description' => $neuerText]);
			} catch (Throwable $fehler) {
				throw $this->scheitert($nummer, $stand, $fehler->getMessage(), $fehler);
			}
		}

		$this->leseZurueck();
	}

	/**
	 * Was geschrieben wurde - und nur das darf zurueckgeschrieben werden.
	 *
	 * @return list<AlterStand>
	 */
	public function geaenderte(): array {
		return $this->geaendert;
	}

	/**
	 * Waehlt den Titel nach der Art des Formulars.
	 *
	 * Verglichen wird mit dem ALTEN Titel, nicht mit der Beschriftung: Der
	 * Titel ist das, was Nextcloud wirklich traegt.
	 */
	private function neuerTitelFuer(AlterStand $stand): string {
		return $this->istWarteliste($stand)
			? $this->plan->neuerTitelWarteliste
			: $this->plan->neuerTitelAnmeldung;
	}

	/**
	 * Waehlt den Ablaufzeitpunkt nach der Art. Sie laufen verschieden aus:
	 * die Anmeldung zum Anmeldeschluss, die Warteliste erst am Kurstag.
	 */
	private function neuerAblaufFuer(AlterStand $stand): int {
		return $this->istWarteliste($stand)
			? $this->plan->ablaufWarteliste
			: $this->plan->ablaufAnmeldung;
	}

	/**
	 * Die Art kommt aus derselben Zerlegung, die leseAllesEin geprueft hat.
	 * Zwei getrennte Rechnungen waeren die Luecke: Die Pruefung liesse nur
	 * zwei Arten durch, und die Auswahl entschiede nach einer anderen Regel.
	 */
	private function istWarteliste(AlterStand $stand): bool {
		return $this->titelmuster->zerlege($stand->titel)->art === Formularart::Warteliste;
	}

	private function scheitert(
		int $nummer,
		AlterStand $stand,
		string $ursache,
		Throwable $previous,
	): VerschiebenFehlgeschlagen {
		return new VerschiebenFehlgeschlagen(
			sprintf('(%s) %s', $stand->beschriftung, $ursache),
			schonGeschrieben: true,
			schritt: $nummer,
			schritteInsgesamt: count($this->gelesen) * 2,
			previous: $previous,
		);
	}

	/**
	 * Prueft, ob das Schreiben gewirkt hat - bei JEDEM Formular des Kurses.
	 *
	 * Noetig, weil keyValuePairs keine Schluesselnamen prueft: Ein
	 * Tippfehler im Feldnamen geht als 200 durch und aendert nichts.
	 *
	 * JEDES, nicht nur das erste: Verpuffte der PATCH auf die Warteliste,
	 * meldete die Seite sonst "Kurs verschoben", waehrend die Anmeldung den
	 * neuen Titel traegt und die Warteliste den alten. Das Paar ist damit
	 * zerrissen - die Uebersicht zeigt zwei halbe Kurse, und die Warteliste
	 * laeuft am alten Kurstag ab.
	 *
	 * @throws VerschiebenFehlgeschlagen
	 */
	private function leseZurueck(): void {
		foreach ($this->gelesen as $stand) {
			$this->leseEinesZurueck($stand);
		}
	}

	/** @throws VerschiebenFehlgeschlagen */
	private function leseEinesZurueck(AlterStand $stand): void {
		try {
			$gelesen = $this->formulare->formularHolen($stand->id);
		} catch (Throwable $fehler) {
			throw new VerschiebenFehlgeschlagen(sprintf(
				'Das Zurücklesen von „%s" misslang: %s',
				$stand->beschriftung, $fehler->getMessage()),
				schonGeschrieben: true, previous: $fehler);
		}

		if ($gelesen->titel !== $this->neuerTitelFuer($stand)) {
			throw new VerschiebenFehlgeschlagen(sprintf(
				'Die Änderung an „%s" kam nicht an: Der Titel lautet weiterhin „%s".',
				$stand->beschriftung, $gelesen->titel), schonGeschrieben: true);
		}

		$frage = $gelesen->frageMitNamen(self::BEDINGUNGSFRAGE);
		if ($frage === null || !str_contains($frage->beschreibung, $this->plan->termin)) {
			throw new VerschiebenFehlgeschlagen(sprintf(
				'Die Änderung an „%s" kam nicht an: Der Termin %s steht nicht '
				. 'im Bedingungstext.',
				$stand->beschriftung, $this->plan->termin), schonGeschrieben: true);
		}
	}
}
