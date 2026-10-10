<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Formulare;

use OCA\Radfahrschule\Formulare\Abgabe;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Formulare\Frage;

/**
 * Testdoppel hinter demselben Interface wie die echte Anbindung.
 *
 * Es beweist nur, dass Code und Doppel sich einig sind. Ob die Annahmen
 * ueber Forms stimmen, beantwortet allein ein Lauf gegen eine echte
 * Instanz.
 */
final class FormulareDoppel implements Formulare {
	/** @var list<string> jeder Aufruf in der Reihenfolge des Eintreffens */
	public array $aufrufe = [];

	/** @var array<int, Formular> */
	private array $vorrat = [];

	private int $naechsteId = 100;

	/** @var list<string> die Aufrufe, bei denen absichtlich gescheitert wird */
	private array $scheitertBei = [];

	/** @var list<string> die Aufrufe, die erst beim zweiten Mal scheitern */
	private array $scheitertAbDemZweitenMal = [];

	/** @var list<string> die Aufrufe, die erst NACH ihrer Wirkung scheitern */
	private array $scheitertNachDerWirkung = [];

	/** @var list<int> die IDs, deren Loeschen scheitern soll */
	private array $loeschenScheitertBei = [];

	private bool $aendernWirkungslos = false;

	/** @var list<int> die Formulare, deren Aenderungen einzeln verpuffen */
	private array $aendernWirkungslosBei = [];

	private bool $frageVerschwindet = false;

	/** @var array<int, array<string, mixed>> die zuletzt geschriebenen Felder je Formular */
	private array $geaendert = [];

	/** @var array<int, list<Empfaenger>> die Eintraege je Formular */
	private array $empfaengerJe = [];

	/** @var array<int, list<Abgabe>> die Abgaben je Formular */
	private array $abgabenJe = [];

	/** @var array<int, list<array<int, list<string>>>> was je Formular eingereicht wurde */
	public array $eingereicht = [];

	/** Ab dem wievielten Einreichen Forms ablehnt. Null: nie. */
	private ?int $einreichenAblehnenAb = null;

	private int $einreichungen = 0;

	/** @param list<Formular> $liste */
	public function __construct(
		array $liste = [],
		private bool $scheitert = false,
	) {
		foreach ($liste as $formular) {
			$this->vorrat[$formular->id] = $formular;
		}
	}

	/** Laesst sich mehrfach aufrufen, um mehrere Aufrufe scheitern zu lassen. */
	public function laessScheitern(string $aufruf): void {
		$this->scheitertBei[] = $aufruf;
	}

	/**
	 * Laesst einen Aufruf erst beim ZWEITEN Mal scheitern.
	 *
	 * Gebraucht fuer das Zurueckschreiben: Es ruft dieselben Aufrufe wie das
	 * Schreiben. Ohne den Zaehler liesse sich der Fall "Schreiben gelang,
	 * Heilen misslingt" gar nicht nachstellen.
	 */
	public function laessBeimZweitenMalScheitern(string $aufruf): void {
		$this->scheitertAbDemZweitenMal[] = $aufruf;
	}

	/**
	 * Laesst einen Aufruf scheitern, NACHDEM er gewirkt hat.
	 *
	 * merke() scheitert vor der Wirkung - das ist der Fall, in dem
	 * Nextcloud den Aufruf gar nicht erst annimmt. Der andere Fall fehlte:
	 * Der Aufruf erreicht Forms, wirkt dort, und die Antwort geht verloren
	 * oder das Timeout greift. Fuer den Anrufer sieht beides gleich aus,
	 * in Nextcloud steht danach aber etwas anderes.
	 */
	public function laessNachDerWirkungScheitern(string $aufruf): void {
		$this->scheitertNachDerWirkung[] = $aufruf;
	}

	public function laessLoeschenScheitern(int $id): void {
		$this->loeschenScheitertBei[] = $id;
	}

	/**
	 * Bildet den Tippfehler im Feldnamen nach: keyValuePairs antwortet mit
	 * 200 und aendert nichts. Der eine Fall, gegen den Schritt 10 steht.
	 */
	public function laessAendernWirkungslos(): void {
		$this->aendernWirkungslos = true;
	}

	/**
	 * Derselbe Fall, aber nur fuer EIN Formular.
	 *
	 * Der Schalter oben schaltet alle zugleich stumm. Damit laesst sich
	 * nicht pruefen, ob das Zuruecklesen wirklich jedes Formular ansieht:
	 * Es genuegte, das eine zu pruefen, das ohnehin auffliegt.
	 */
	public function laessAendernWirkungslosBei(int $id): void {
		$this->aendernWirkungslosBei[] = $id;
	}

	/**
	 * Nach dem Schreiben ist die Frage nicht mehr da.
	 *
	 * Bildet den zweiten Fall nach, gegen den das Zuruecklesen steht: Der
	 * Aufruf gelingt, aber das zurueckgelesene Formular traegt die
	 * Bedingungsfrage nicht mehr. Ohne diesen Schalter bleibt die Pruefung
	 * auf "Frage fehlt" ungetestet - der Text-Vergleich daneben deckt sie
	 * nicht mit ab.
	 */
	public function laessFrageVerschwinden(): void {
		$this->frageVerschwindet = true;
	}

	/**
	 * Die Felder, die zuletzt an dieses Formular geschrieben wurden.
	 *
	 * Ohne sie liesse sich nur pruefen, DASS geaendert wurde - nicht, womit.
	 * Genau daran haengt aber der Unterschied zwischen Anmeldung und
	 * Warteliste.
	 *
	 * @return array<string, mixed>
	 */
	public function zuletztGeaendert(int $id): array {
		return $this->geaendert[$id] ?? [];
	}

	/** @return list<Formular> was noch steht */
	public function bestand(): array {
		return array_values($this->vorrat);
	}

	public function alleEigenen(): array {
		$this->merke('alleEigenen');
		if ($this->scheitert) {
			throw new FormulareNichtErreichbar('Testdoppel scheitert absichtlich');
		}
		return array_values($this->vorrat);
	}

	public function formularHolen(int $id): Formular {
		$this->merke('formularHolen:' . $id);
		return $this->vorrat[$id] ?? throw new FormulareNichtErreichbar(
			'Formular ' . $id . ' gibt es im Doppel nicht.');
	}

	public function formularKlonen(int $vorlageId): Formular {
		$this->merke('formularKlonen:' . $vorlageId);
		$vorlage = $this->vorrat[$vorlageId] ?? throw new FormulareNichtErreichbar(
			'Vorlage ' . $vorlageId . ' gibt es im Doppel nicht.');

		// Erst die id festhalten, dann den Hash bauen - sonst traegt
		// Formular 100 den Hash von 101.
		$id = $this->naechsteId++;
		$klon = new Formular(
			id: $id,
			// Der Editor-Hash hat 16 Zeichen. Die Laenge ist hier
			// funktionaler Teil des Doppels: Sie trennt ihn vom Share-Hash.
			hash: 'editor' . str_pad((string)$id, 10, '0', STR_PAD_LEFT),
			titel: $vorlage->titel . ' - Kopie',
			beschreibung: $vorlage->beschreibung,
			abgaben: 0,
			// Ein Klon bringt expires NICHT mit.
			ablauf: 0,
			fragen: $vorlage->fragen,
			freigaben: [],
			platzzahl: $vorlage->platzzahl,
		);
		$this->vorrat[$id] = $klon;
		$this->pruefeNachwirkung('formularKlonen:' . $vorlageId);
		return $klon;
	}

	public function formularAendern(int $id, array $felder): void {
		$this->merke('formularAendern:' . $id);

		// Gemerkt wird auch im wirkungslosen Fall: Nextcloud NIMMT den
		// Aufruf an, es wirkt nur nichts.
		$this->geaendert[$id] = $felder;
		if ($this->istWirkungslos($id)) {
			return;
		}

		$alt = $this->vorrat[$id];
		$this->vorrat[$id] = new Formular(
			id: $alt->id,
			hash: $alt->hash,
			titel: (string)($felder['title'] ?? $alt->titel),
			beschreibung: (string)($felder['description'] ?? $alt->beschreibung),
			abgaben: $alt->abgaben,
			ablauf: (int)($felder['expires'] ?? $alt->ablauf),
			fragen: $alt->fragen,
			freigaben: $alt->freigaben,
			platzzahl: $alt->platzzahl,
		);
		$this->pruefeNachwirkung('formularAendern:' . $id);
	}

	/**
	 * Schreibt den neuen Text wirklich in die Frage.
	 *
	 * Ohne das faende leseZurueck den neuen Termin nie - und der Test, der
	 * das Zuruecklesen absichert, waere auch dann gruen, wenn es fehlt.
	 */
	public function frageAendern(int $formularId, int $frageId, array $felder): void {
		$this->merke('frageAendern:' . $formularId . '/' . $frageId);

		if ($this->istWirkungslos($formularId) || !isset($this->vorrat[$formularId])) {
			return;
		}

		$alt = $this->vorrat[$formularId];
		$fragen = [];
		foreach ($alt->fragen as $frage) {
			if ($frage->id === $frageId && $this->frageVerschwindet) {
				continue;
			}
			$fragen[] = $frage->id === $frageId
				? new Frage($frage->id, $frage->name, $frage->text,
					(string)($felder['description'] ?? $frage->beschreibung), $frage->auswahl)
				: $frage;
		}

		$this->vorrat[$formularId] = new Formular(
			id: $alt->id,
			hash: $alt->hash,
			titel: $alt->titel,
			beschreibung: $alt->beschreibung,
			abgaben: $alt->abgaben,
			ablauf: $alt->ablauf,
			fragen: $fragen,
			freigaben: $alt->freigaben,
			platzzahl: $alt->platzzahl,
		);
		$this->pruefeNachwirkung('frageAendern:' . $formularId . '/' . $frageId);
	}

	public function formularLoeschen(int $id): void {
		$this->merke('formularLoeschen:' . $id);
		if (in_array($id, $this->loeschenScheitertBei, true)) {
			throw new FormulareNichtErreichbar('Loeschen scheitert absichtlich');
		}
		unset($this->vorrat[$id]);
	}

	public function linkFreigabeAnlegen(int $formularId): string {
		$this->merke('linkFreigabeAnlegen:' . $formularId);
		// 24 Zeichen - der Share-Hash, nicht der Editor-Hash.
		return 'share' . str_pad((string)$formularId, 19, '0', STR_PAD_LEFT);
	}

	public function gruppenFreigabeAnlegen(int $formularId, string $gruppe): void {
		$this->merke('gruppenFreigabeAnlegen:' . $formularId);
	}

	/** @param list<Empfaenger> $empfaenger */
	public function setzeEmpfaenger(int $formularId, array $empfaenger): void {
		$this->empfaengerJe[$formularId] = $empfaenger;
	}

	public function empfaenger(int $formularId): array {
		$this->merke('empfaenger:' . $formularId);
		return $this->empfaengerJe[$formularId] ?? [];
	}

	/** @param list<Abgabe> $abgaben */
	public function setzeAbgaben(int $formularId, array $abgaben): void {
		$this->abgabenJe[$formularId] = $abgaben;
	}

	/**
	 * Bildet nach, dass sich zwischendurch jemand ueber das oeffentliche
	 * Formular anmeldet: Ab dem genannten Einreichen lehnt Forms mit 403 ab.
	 */
	public function laessEinreichenAblehnenAb(int $nummerDesAufrufs): void {
		$this->einreichenAblehnenAb = $nummerDesAufrufs;
	}

	public function abgaben(int $formularId): array {
		$this->merke('abgaben:' . $formularId);
		return $this->abgabenJe[$formularId] ?? [];
	}

	/**
	 * Zaehlt die Abgabe wirklich mit und lehnt ab, wenn das Formular voll
	 * ist. Ein Doppel, das nur den Aufruf merkt, hielte die Pruefung auf
	 * freie Plaetze nicht.
	 */
	public function abgabeEinreichen(int $formularId, array $antworten): void {
		$aufruf = 'abgabeEinreichen:' . $formularId;
		$this->merke($aufruf);
		$this->einreichungen++;

		$formular = $this->vorrat[$formularId] ?? throw new FormulareNichtErreichbar(
			'Formular ' . $formularId . ' gibt es im Doppel nicht.', 404);
		$abgelehnt = $this->einreichenAblehnenAb !== null
			&& $this->einreichungen >= $this->einreichenAblehnenAb;
		if ($abgelehnt || $formular->freiePlaetze() === 0) {
			throw new FormulareNichtErreichbar('Testdoppel: Das Formular ist voll.', 403);
		}

		$this->eingereicht[$formularId][] = $antworten;
		$this->vorrat[$formularId] = new Formular(
			id: $formular->id,
			hash: $formular->hash,
			titel: $formular->titel,
			beschreibung: $formular->beschreibung,
			abgaben: $formular->abgaben + 1,
			ablauf: $formular->ablauf,
			fragen: $formular->fragen,
			freigaben: $formular->freigaben,
			platzzahl: $formular->platzzahl,
		);
		$this->pruefeNachwirkung($aufruf);
	}

	public function abgabeLoeschen(int $formularId, int $abgabeId): void {
		$this->merke('abgabeLoeschen:' . $formularId . '/' . $abgabeId);

		$bleiben = [];
		foreach ($this->abgabenJe[$formularId] ?? [] as $abgabe) {
			if ($abgabe->id !== $abgabeId) {
				$bleiben[] = $abgabe;
			}
		}
		$this->abgabenJe[$formularId] = $bleiben;
	}

	/**
	 * Haelt den Aufruf fest und scheitert notfalls VOR der Wirkung. So
	 * bildet das Doppel den Fall nach, in dem Nextcloud den Aufruf gar nicht
	 * erst annimmt.
	 */
	/** Scheitert, nachdem der Aufruf gewirkt hat. */
	private function pruefeNachwirkung(string $aufruf): void {
		if (in_array($aufruf, $this->scheitertNachDerWirkung, true)) {
			throw new FormulareNichtErreichbar(
				'Testdoppel verliert die Antwort auf ' . $aufruf);
		}
	}

	private function istWirkungslos(int $id): bool {
		return $this->aendernWirkungslos
			|| in_array($id, $this->aendernWirkungslosBei, true);
	}

	private function merke(string $aufruf): void {
		$this->aufrufe[] = $aufruf;

		if (in_array($aufruf, $this->scheitertBei, true)) {
			throw new FormulareNichtErreichbar('Testdoppel scheitert bei ' . $aufruf);
		}

		// Gezaehlt wird ueber die schon festgehaltenen Aufrufe: Steht dieser
		// dort ein zweites Mal, ist es der Wiederholungsfall.
		$schonGesehen = count(array_filter(
			$this->aufrufe, static fn (string $frueher): bool => $frueher === $aufruf));
		if ($schonGesehen > 1 && in_array($aufruf, $this->scheitertAbDemZweitenMal, true)) {
			throw new FormulareNichtErreichbar('Testdoppel scheitert erneut bei ' . $aufruf);
		}
	}
}
