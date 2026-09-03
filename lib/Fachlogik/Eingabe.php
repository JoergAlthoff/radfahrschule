<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;

/**
 * Die Angaben, aus denen ein Kurs entsteht.
 *
 * Geprueft wird vor der Vorschau und noch einmal vor dem Anlegen. Die
 * versteckten Felder der Vorschau sind Eingaben wie alle anderen - wer sie
 * veraendert, darf damit nicht an der Pruefung vorbeikommen.
 */
final readonly class Eingabe {
	public function __construct(
		public int $vorlageAnmeldung,
		public int $vorlageWarteliste,
		public DateTimeImmutable $von,
		public DateTimeImmutable $bis,
		public DateTimeImmutable $anmeldeschluss,
		public int $plaetze,
	) {
	}

	/**
	 * Die Meldung, oder null, wenn die Angaben passen.
	 *
	 * jetzt kommt als Parameter statt aus der Uhr: Sonst waere die Pruefung
	 * auf die Vergangenheit nicht pruefbar, und die Testdaten liefen
	 * irgendwann ab.
	 */
	public function pruefe(DateTimeImmutable $jetzt): ?string {
		if ($this->vorlageAnmeldung <= 0) {
			return 'Es ist keine Vorlage für die Anmeldung gewählt.';
		}
		if ($this->vorlageWarteliste <= 0) {
			return 'Es ist keine Vorlage für die Warteliste gewählt.';
		}
		if ($this->vorlageAnmeldung === $this->vorlageWarteliste) {
			return 'Anmeldung und Warteliste dürfen nicht dasselbe Formular sein.';
		}
		return $this->pruefeTermin($jetzt) ?? $this->pruefePlaetze();
	}

	/**
	 * Die drei Regeln, die fuer jeden Kurstermin gelten - gleich ob er neu
	 * entsteht oder verschoben wird.
	 *
	 * Sie stehen hier und nicht zweimal: Liefe eine Seite der anderen davon,
	 * verboete sie, was die andere erlaubt, und das faellt erst dem Benutzer
	 * auf.
	 */
	private function pruefeTermin(DateTimeImmutable $jetzt): ?string {
		if ($this->bis < $this->von) {
			return 'Der letzte Kurstag liegt vor dem ersten.';
		}
		if ($this->anmeldeschluss > $this->von) {
			return 'Der Anmeldeschluss liegt nach dem Kursbeginn.';
		}
		// Der Kurstag selbst zaehlt noch. Deshalb der Kalendertag und nicht
		// der Zeitpunkt - sonst waere ein heutiger Kurs ab 00:01 abgelehnt.
		if (self::kalendertag($this->bis) < self::kalendertag($jetzt)) {
			return 'Der letzte Kurstag liegt in der Vergangenheit.';
		}
		return null;
	}

	private function pruefePlaetze(): ?string {
		if ($this->plaetze <= 0) {
			return 'Die Platzzahl muss größer als null sein.';
		}
		return null;
	}

	/** Schneidet die Uhrzeit ab. Zur Zone siehe Zeitzone::ABLAGE. */
	private static function kalendertag(DateTimeImmutable $zeitpunkt): DateTimeImmutable {
		return new DateTimeImmutable(
			$zeitpunkt->format('Y-m-d') . ' 00:00:00',
			Zeitzone::zumAblegen(),
		);
	}

	public function mitVorlagen(int $anmeldung, int $warteliste): self {
		return new self($anmeldung, $warteliste, $this->von, $this->bis,
			$this->anmeldeschluss, $this->plaetze);
	}

	public function mitTagen(string $von, string $bis): self {
		$zone = Zeitzone::zumAblegen();
		return new self($this->vorlageAnmeldung, $this->vorlageWarteliste,
			new DateTimeImmutable($von, $zone), new DateTimeImmutable($bis, $zone),
			$this->anmeldeschluss, $this->plaetze);
	}

	public function mitAnmeldeschluss(string $tag): self {
		return new self($this->vorlageAnmeldung, $this->vorlageWarteliste,
			$this->von, $this->bis,
			new DateTimeImmutable($tag, Zeitzone::zumAblegen()), $this->plaetze);
	}

	public function mitPlaetzen(int $plaetze): self {
		return new self($this->vorlageAnmeldung, $this->vorlageWarteliste,
			$this->von, $this->bis, $this->anmeldeschluss, $plaetze);
	}
}
