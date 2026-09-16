<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use OCA\Radfahrschule\Benachrichtigung\Nachricht;
use OCA\Radfahrschule\Benachrichtigung\Versand;
use OCA\Radfahrschule\Benachrichtigung\Versandergebnis;
use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Formulare\Empfaenger;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Protokoll\Protokoll;
use OCA\Radfahrschule\Protokoll\Vorgang;

/**
 * Schickt eine Nachricht an die Eintraege eines Kurses.
 *
 * Jeder Empfaenger bekommt eine eigene Mail. Die Adressen leben nur fuer die
 * Dauer dieses Aufrufs; gespeichert wird nichts, und das Protokoll bekommt
 * nur Zahlen.
 *
 * Gemeint sind Anmeldung und Warteliste. Ein Formular ohne Art oder ein
 * doppelt angelegtes bekommt nichts: Welcher Text dorthin gehoerte, weiss
 * niemand.
 */
final readonly class Benachrichtigen {
	private const FRAGE_EMAIL = 'email';

	public function __construct(
		private Formulare $formulare,
		private Versand $versand,
		private Protokoll $protokoll,
		private Betreiberangaben $betreiberangaben,
	) {
	}

	public function versandIstAbgeschaltet(): bool {
		return $this->versand->istAbgeschaltet();
	}

	/**
	 * Nennt das Formular, dem die Frage "email" fehlt, oder null.
	 *
	 * Ohne sie kennt die App keine Adresse. Gesucht wird ueber den
	 * technischen Namen - die Liste der Formulare traegt keine Fragen, also
	 * wird jedes einzeln geholt.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function fehlendeAngabe(Kurs $kurs): ?string {
		foreach ($this->listen($kurs) as $formular) {
			$gelesen = $this->formulare->formularHolen($formular->id);
			if ($gelesen->frageMitNamen(self::FRAGE_EMAIL) === null) {
				return sprintf(
					'Im Formular „%s" gibt es keine Frage mit dem technischen Namen „%s". '
					. 'Ohne sie kennt die App keine Adresse und verschickt nichts.',
					$gelesen->titel, self::FRAGE_EMAIL);
			}
		}
		return null;
	}

	/**
	 * Erst alle Adressen, dann die erste Mail.
	 *
	 * Scheitert das Lesen der zweiten Liste, ist noch nichts hinaus. Sonst
	 * haette die Haelfte eine Nachricht, und die Seite zeigte einen Fehler.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function verschicke(
		Kurs $kurs,
		Nachricht $anAngemeldete,
		Nachricht $anWartende,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Versandergebnis {
		$auftraege = $this->auftraege($kurs, $anAngemeldete, $anWartende);
		return $this->schicke($kurs, $auftraege, $benutzer, $jetzt);
	}

	/**
	 * Eine Nachricht zu diesem Kurs: Kursart aus der Einstellung, Termin aus
	 * dem Kurs, so wie beide im Formulartitel stehen.
	 *
	 * Den neuen Termin kennt nur, wer verschiebt. Sonst bleibt er leer.
	 */
	public function nachricht(Kurs $kurs, string $betreff, string $text, string $neuerTermin = ''): Nachricht {
		return new Nachricht(
			betreff: $betreff,
			text: $text,
			kursart: $this->betreiberangaben->kursartRoh(),
			termin: self::termin($kurs),
			neuerTermin: $neuerTermin,
		);
	}

	/**
	 * Liest die Adressen und ordnet jedem Empfaenger die Nachricht seiner
	 * Liste zu. Verschickt wird hier nichts.
	 *
	 * Beim Loeschen muessen die Adressen gelesen sein, bevor die Formulare
	 * weg sind. Danach gibt es sie nicht mehr.
	 *
	 * @return list<array{empfaenger: Empfaenger, nachricht: Nachricht}>
	 * @throws FormulareNichtErreichbar
	 */
	public function auftraege(Kurs $kurs, Nachricht $anAngemeldete, Nachricht $anWartende): array {
		$angemeldete = $this->empfaengerVon($kurs->anmeldung, $anAngemeldete);
		$wartende = $this->empfaengerVon($kurs->warteliste, $anWartende);

		$auftraege = [];
		foreach ($angemeldete as $empfaenger) {
			$auftraege[] = ['empfaenger' => $empfaenger, 'nachricht' => $anAngemeldete];
		}
		foreach ($wartende as $empfaenger) {
			$auftraege[] = ['empfaenger' => $empfaenger, 'nachricht' => $anWartende];
		}
		return $auftraege;
	}

	/**
	 * Verschickt gesammelte Auftraege, jeden einzeln. Eine scheiternde Mail
	 * haelt die anderen nicht auf.
	 *
	 * @param list<array{empfaenger: Empfaenger, nachricht: Nachricht}> $auftraege
	 */
	public function schicke(
		Kurs $kurs,
		array $auftraege,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Versandergebnis {
		$verschickt = 0;
		$gescheitert = [];
		foreach ($auftraege as $auftrag) {
			$persoenlich = $auftrag['nachricht']->fuer($auftrag['empfaenger']);
			if ($this->versand->schicke($auftrag['empfaenger'], $persoenlich)) {
				$verschickt++;
			} else {
				$gescheitert[] = $auftrag['empfaenger']->name();
			}
		}

		$this->protokoll->schreibe(Vorgang::benachrichtigt(
			jetzt: $jetzt,
			benutzer: $benutzer,
			kennung: $kurs->kennung,
			kurstag: $kurs->kurstagIso(),
			verschickt: $verschickt,
			gescheitert: count($gescheitert),
		));

		return new Versandergebnis($verschickt, $gescheitert);
	}

	/**
	 * Die Eintraege eines Formulars - oder keine, wenn es fehlt oder sein
	 * Text leer ist. Eine Liste, die nichts bekommt, wird nicht gelesen.
	 *
	 * @return list<Empfaenger>
	 * @throws FormulareNichtErreichbar
	 */
	private function empfaengerVon(?Formular $formular, Nachricht $nachricht): array {
		if ($formular === null || $nachricht->istLeer()) {
			return [];
		}
		return $this->formulare->empfaenger($formular->id);
	}

	/** @return list<Formular> Anmeldung und Warteliste, soweit vorhanden */
	private function listen(Kurs $kurs): array {
		return array_values(array_filter([$kurs->anmeldung, $kurs->warteliste]));
	}

	/** "12./13.09.2026", wie im Formulartitel. */
	private static function termin(Kurs $kurs): string {
		if ($kurs->letzterTag === null) {
			return '';
		}
		return Termin::kurz($kurs->ersterTag ?? $kurs->letzterTag, $kurs->letzterTag);
	}
}
