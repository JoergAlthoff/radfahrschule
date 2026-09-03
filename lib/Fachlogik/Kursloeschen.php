<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use Throwable;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Protokoll\Protokoll;
use OCA\Radfahrschule\Protokoll\Vorgang;
use OCA\Radfahrschule\Sperre\Schreibsperre;

/**
 * Entfernt beide Formulare eines Kurses.
 *
 * Gesucht wird ueber die KENNUNG und nicht ueber ids. Das erledigt zwei
 * Pruefungen, ohne sie zu schreiben: Gemischte ids aus zwei Kursen sind
 * unmoeglich, und eine Vorlage ist nie treffbar - sie traegt keine Kennung.
 * Eine Pruefung, die man vergessen kann, ist schlechter als eine, die es
 * nicht gibt.
 *
 * Es gibt keine Transaktion. Scheitert der zweite Aufruf, bleibt das erste
 * Formular geloescht.
 *
 * Zurueckgerollt wird hier NICHT, anders als beim Anlegen: Ein geloeschtes
 * Formular laesst sich nicht wiederherstellen. Es GIBT nichts
 * zurueckzurollen - der Aufrufer erfaehrt deshalb, was noch steht.
 *
 * Dieselbe Sperre wie beim Anlegen und Verschieben.
 *
 * Zwei gleichzeitige Loeschungen desselben Kurses waeren harmlos - die
 * zweite faende nichts mehr. Das ist nicht der Fall, um den es geht.
 *
 * Gefaehrlich ist Loeschen GEGEN Verschieben. Der Verschiebende haelt die
 * Sperre, aendert Titel und Fragen; wer gleichzeitig loescht, zieht ihm die
 * Formulare unter den Haenden weg. Sein naechster Schritt scheitert, und das
 * Zurueckrollen ebenso. Im Protokoll steht danach ein abgebrochener Vorgang,
 * der wie ein Fehler der App aussieht.
 */
final readonly class Kursloeschen {
	public function __construct(
		private Formulare $formulare,
		private Protokoll $protokoll,
		private Schreibsperre $sperre,
		private Zugangsdaten $zugangsdaten,
		private Titelmuster $titelmuster,
	) {
	}

	/**
	 * @throws GeradeBeschaeftigt
	 * @throws KursNichtGefunden
	 * @throws LoeschenFehlgeschlagen
	 */
	public function loesche(
		string $kennung,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Kurs {
		$this->sperre->nimm();
		try {
			return $this->loescheGesperrt($kennung, $benutzer, $jetzt);
		} finally {
			$this->sperre->gib();
		}
	}

	/**
	 * @throws KursNichtGefunden
	 * @throws LoeschenFehlgeschlagen
	 */
	private function loescheGesperrt(
		string $kennung,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Kurs {
		$kurs = $this->kursMitKennung($kennung);

		// Die Zaehler VOR dem Loeschen festhalten: Danach gibt es die
		// Formulare nicht mehr, und das Protokoll soll sagen, wie viele
		// Anmeldungen verschwunden sind.
		$anmeldungen = $kurs->anmeldungen();
		$anmeldungId = $kurs->anmeldung?->id;
		$wartelisteId = $kurs->warteliste?->id;

		$stehengeblieben = [];
		$ersteUrsache = null;

		foreach ($kurs->zeilen() as $zeile) {
			try {
				$this->formulare->formularLoeschen($zeile->formular->id);
			} catch (Throwable $fehler) {
				// Ein Fehlschlag darf die uebrigen nicht aufhalten - sonst
				// bleibt mehr stehen als noetig, und zwar ungenannt. Dieselbe
				// Entscheidung wie in Zurueckrollen::raeumeAuf.
				$stehengeblieben[] = $zeile;
				$ersteUrsache ??= $fehler->getMessage();
			}
		}

		if ($stehengeblieben !== []) {
			$this->protokoll->schreibe(Vorgang::loeschenAbgebrochen(
				jetzt: $jetzt,
				benutzer: $benutzer,
				kennung: $kurs->kennung,
				kurstag: $kurs->kurstagIso(),
				anmeldungId: self::idMit($stehengeblieben, 'Anmeldung'),
				wartelisteId: self::idMit($stehengeblieben, 'Warteliste'),
				grund: $ersteUrsache ?? '',
			));

			throw new LoeschenFehlgeschlagen(
				$this->hinweis($stehengeblieben, $ersteUrsache ?? ''));
		}

		$this->protokoll->schreibe(Vorgang::geloescht(
			jetzt: $jetzt,
			benutzer: $benutzer,
			kennung: $kurs->kennung,
			kurstag: $kurs->kurstagIso(),
			anmeldungId: $anmeldungId,
			wartelisteId: $wartelisteId,
			anmeldungen: $anmeldungen,
		));

		return $kurs;
	}

	/** @throws KursNichtGefunden */
	private function kursMitKennung(string $kennung): Kurs {
		foreach (Kursliste::ausFormularen($this->formulare->alleEigenen(), $this->titelmuster) as $kurs) {
			if ($kurs->kennung === $kennung) {
				return $kurs;
			}
		}
		throw new KursNichtGefunden($kennung);
	}

	/**
	 * Die id des steckengebliebenen Formulars einer Art, oder null.
	 *
	 * @param list<Kurszeile> $stehengeblieben
	 */
	private static function idMit(array $stehengeblieben, string $beschriftung): ?int {
		foreach ($stehengeblieben as $zeile) {
			if ($zeile->beschriftung === $beschriftung) {
				return $zeile->formular->id;
			}
		}
		return null;
	}

	/**
	 * Sagt, was noch in Nextcloud steht - mit Link, nicht mit Nummer.
	 *
	 * In Nextcloud ist nirgends eine Formularnummer zu sehen: Die Liste
	 * zeigt Titel, die Editor-Adresse zeigt einen Hash. Wer hier aufraeumen
	 * soll, braucht etwas zum Anklicken.
	 *
	 * Die Ursache steht einmal am Ende. Bleiben mehrere stehen, ist es in
	 * aller Regel dieselbe - jede einzeln zu wiederholen machte die Liste
	 * nur laenger, nicht klarer. Der volle Wortlaut jedes Fehlschlags steht
	 * ohnehin im Nextcloud-Protokoll.
	 *
	 * @param list<Kurszeile> $stehengeblieben
	 */
	private function hinweis(array $stehengeblieben, string $ursache): string {
		$zeilen = ['Das Löschen brach ab. Diese Formulare stehen noch in Nextcloud:'];

		foreach ($stehengeblieben as $zeile) {
			$zeilen[] = '';
			$zeilen[] = '  ' . $zeile->beschriftung;
			$zeilen[] = '  ' . $this->editorAdresse($zeile->formular->hash);
		}

		$zeilen[] = '';
		$zeilen[] = '  Grund: ' . $ursache;
		$zeilen[] = '';
		$zeilen[] = 'Was schon weg ist, bleibt weg. Bitte dort löschen.';

		return implode("\n", $zeilen);
	}

	/**
	 * Der EDITOR-Hash (16 Zeichen), nicht der oeffentliche Share-Hash (24).
	 * Gemeint ist die Seite zum Bearbeiten und Loeschen, nicht die zum
	 * Anmelden.
	 */
	private function editorAdresse(string $hash): string {
		return rtrim($this->zugangsdaten->basisUrl(), '/') . '/apps/forms/' . $hash . '/edit';
	}
}
