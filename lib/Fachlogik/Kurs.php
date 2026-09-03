<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use OCA\Radfahrschule\Formulare\Formular;

/**
 * Ein Kurs sind zwei Formulare, geklammert durch die gemeinsame Kennung.
 * Nextcloud kennt keine Kurse.
 */
final class Kurs {
	private const KURSTAGSFORMAT = 'd.m.Y';

	/** @param list<Formular> $weitere */
	public function __construct(
		public readonly string $kennung,
		public ?Formular $anmeldung = null,
		public ?Formular $warteliste = null,
		public ?DateTimeImmutable $letzterTag = null,
		/**
		 * Der erste Kurstag. Bei einem eintaegigen Kurs derselbe wie
		 * letzterTag.
		 *
		 * Gebraucht vom Verschiebeformular: Es fuellte beide Datumsfelder
		 * mit letzterTag, und aus "12./13.09." wurde beim Verschieben
		 * unbemerkt ein einziger Tag oder eine Woche.
		 */
		public ?DateTimeImmutable $ersterTag = null,
		/** Ein Formular, dessen Titel keine Art nennt. */
		public ?Formular $sonstiges = null,
		/** Zweite Formulare derselben Art - der Doppelklick-Fall. */
		public array $weitere = [],
	) {
	}

	public function anmeldungen(): int {
		return $this->anmeldung->abgaben ?? 0;
	}

	public function wartende(): int {
		return $this->warteliste->abgaben ?? 0;
	}

	/**
	 * Die Formulare des Kurses in fester Reihenfolge.
	 *
	 * Sie ist zugleich die LOESCHREIHENFOLGE: Die Anmeldung geht zuerst.
	 * Bricht es danach ab, bleibt eine Warteliste ohne Anmeldung - unschoen,
	 * aber harmlos. Andersherum stuende eine Anmeldung da, deren
	 * Wartelisten-Link ins Leere fuehrt, und darueber stolpert jemand, der
	 * sich anmelden will.
	 *
	 * Die doppelt angelegten MUESSEN hier stehen: Was in dieser Liste fehlt,
	 * bleibt in Nextcloud zurueck, waehrend das Protokoll Erfolg meldet.
	 *
	 * @return list<Kurszeile>
	 */
	public function zeilen(): array {
		$zeilen = [];

		if ($this->anmeldung !== null) {
			$zeilen[] = new Kurszeile('Anmeldung', $this->anmeldung);
		}
		if ($this->warteliste !== null) {
			$zeilen[] = new Kurszeile('Warteliste', $this->warteliste);
		}
		if ($this->sonstiges !== null) {
			$zeilen[] = new Kurszeile('Ohne Art', $this->sonstiges);
		}

		// Die doppelten zuletzt - sie sind der Sonderfall und sollen die
		// gewohnte Reihenfolge nicht durcheinanderbringen.
		foreach ($this->weitere as $formular) {
			$zeilen[] = new Kurszeile('Doppelt angelegt', $formular);
		}

		return $zeilen;
	}

	public function istDoppelt(): bool {
		return $this->weitere !== [];
	}

	/**
	 * Die Haelfte, die diesem Kurs fehlt, oder null.
	 *
	 * Ein Kurs besteht aus Anmeldung und Warteliste. Zusammen halten sie nur
	 * ihre Titel: Wird eines der beiden in Nextcloud umbenannt, faellt es
	 * aus dem Paar und bildet einen eigenen Eintrag. Was bleibt, sieht aus
	 * wie ein vollstaendiger Kurs.
	 *
	 * Das faellt genau dort auf die Fuesse, wo es teuer ist. Wer den Rest
	 * loescht, bekaeme "samt Anmeldedaten entfernt" zu lesen, waehrend die
	 * andere Haelfte mit ihren Eintraegen stehenbleibt. Die Loeschfrist
	 * laeuft weiter, nur sieht niemand mehr nach.
	 *
	 * Ein Formular ohne Art im selben Kurs beendet die Frage: Es ist der
	 * wahrscheinlichste Kandidat FUER die umbenannte Haelfte, und zeilen() -
	 * die Loeschliste - fasst es an. Zu melden, es bleibe etwas stehen,
	 * waere dann genau verkehrt herum.
	 *
	 * Doppelt angelegte bleiben aussen vor: Sie sind derselben Art wie das
	 * Formular, das schon da ist, ersetzen die fehlende Haelfte also nicht.
	 * Sie meldet istDoppelt.
	 */
	public function fehlendeHaelfte(): ?string {
		if ($this->sonstiges !== null) {
			return null;
		}
		if ($this->anmeldung !== null && $this->warteliste === null) {
			return 'Warteliste';
		}
		if ($this->warteliste !== null && $this->anmeldung === null) {
			return 'Anmeldung';
		}
		// Auch der Fall, dass beide fehlen: Dann ist es kein halber Kurs,
		// sondern ein Eintrag aus einem Formular ohne erkennbare Art.
		return null;
	}

	/**
	 * Die id, unter der die Detailseite diesen Kurs findet. Null hiesse: Der
	 * Kurs hat kein Formular, was nicht vorkommen kann.
	 */
	public function eineId(): int {
		$zeilen = $this->zeilen();
		return $zeilen === [] ? 0 : $zeilen[0]->formular->id;
	}

	/**
	 * Nennt den letzten Kurstag in der richtigen Zeitform.
	 *
	 * Die Zeitform darf NICHT in der Vorlage stehen: Dort waere sie fest,
	 * und ein kommender Kurs laese sich, als sei er vorbei - beim Loeschen
	 * die gefaehrlichste Verwechslung, die es hier gibt.
	 *
	 * Der Kurstag selbst zaehlt zur Gegenwart. Verglichen wird deshalb auf
	 * Tagesebene, sonst waere der Kurs ab 00:01 gewesen, waehrend er noch
	 * laeuft.
	 */
	public function kurstagSatz(DateTimeImmutable $jetzt): string {
		if ($this->letzterTag === null) {
			return '';
		}

		$zeitform = self::kalendertag($jetzt) > $this->letzterTag ? 'war' : 'ist';

		return 'Der Kurs ' . $zeitform . ' am ' . $this->kurstagDeutsch() . '.';
	}

	public function kurstagDeutsch(): string {
		return $this->letzterTag?->format(self::KURSTAGSFORMAT) ?? '';
	}

	/**
	 * Derselbe Tag fuers Protokoll. ISO, weil sich das sortieren laesst -
	 * die deutsche Schreibweise nicht.
	 */
	public function kurstagIso(): string {
		return $this->letzterTag?->format('Y-m-d') ?? '';
	}

	private static function kalendertag(DateTimeImmutable $zeitpunkt): DateTimeImmutable {
		return new DateTimeImmutable(
			$zeitpunkt->format('Y-m-d') . ' 00:00:00',
			Zeitzone::zumAblegen(),
		);
	}
}
