<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Settings;

use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Meldet den Eintrag "Radfahrschule" in der linken Spalte der
 * Administrationseinstellungen an.
 *
 * Ohne ihn landet die Einstellungsseite unter "Zusaetzliche Einstellungen",
 * wo sich die Bloecke aller Apps untereinander stapeln. Diese Klasse haelt
 * keine Daten - sie liefert nur Kennung, Name, Reihenfolge und Icon-Pfad.
 */
class Einstellungsbereich implements IIconSection {
	/**
	 * Der Bereich, in dem die Seite liegt.
	 *
	 * Als Konstante, weil dieselbe Zeichenkette an drei Stellen gebraucht
	 * wird: hier, in Verwaltung::getSection() und im Sprung nach dem
	 * Speichern. Abgeschrieben liefe sie auseinander - und wer speicherte,
	 * landete auf einer fremden Seite, ohne dass etwas einen Fehler
	 * meldet.
	 */
	public const KENNUNG = 'radfahrschule';

	public function __construct(
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return self::KENNUNG;
	}

	public function getName(): string {
		return 'Radfahrschule';
	}

	/**
	 * Zwischen 0 und 99, aufsteigend sortiert. 55 wie bei Aktivitaet - also
	 * unterhalb der Nextcloud-eigenen Bereiche.
	 */
	public function getPriority(): int {
		return 55;
	}

	/**
	 * Die dunkle Variante, nicht das weisse Icon der Kopfzeile: Die linke
	 * Spalte der Einstellungen hat einen hellen Hintergrund. Nextcloud dreht
	 * das Symbol selbst um, wenn das Design dunkel ist.
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath('radfahrschule', 'radfahrschule-dark.svg');
	}
}
