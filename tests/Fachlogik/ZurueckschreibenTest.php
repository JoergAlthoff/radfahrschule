<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use OCA\Radfahrschule\Fachlogik\AlterStand;
use OCA\Radfahrschule\Fachlogik\Zurueckschreiben;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Formulare\Frage;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use PHPUnit\Framework\TestCase;

final class ZurueckschreibenTest extends TestCase {
	private const BASIS = 'https://cloud.example.org';
	private const ALTER_TEXT = "- **Termin:** 12./13.09.2026\n";

	private function doppel(): FormulareDoppel {
		return new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 14./15.11.2026',
				beschreibung: '', abgaben: 6, ablauf: 9_000_000,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', 'neuer Text')]),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 14./15.11.2026',
				beschreibung: '', abgaben: 2, ablauf: 9_500_000,
				fragen: [new Frage(40, 'teilnahmebedingungen', 'B', 'neuer Text')]),
		]);
	}

	private function stand(int $id, string $titel, int $frageId): AlterStand {
		return new AlterStand(
			beschriftung: $id === 19 ? 'Anmeldung' : 'Warteliste',
			id: $id,
			hash: 'editor' . str_pad((string)$id, 10, '0', STR_PAD_LEFT),
			titel: $titel,
			ablauf: 1_000_000,
			frageId: $frageId,
			fragetext: self::ALTER_TEXT,
		);
	}

	public function testDerAlteTitelStehtWiederDa(): void {
		$doppel = $this->doppel();
		$stehengeblieben = (new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertSame([], $stehengeblieben);
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			$doppel->formularHolen(19)->titel);
		$this->assertSame(1_000_000, $doppel->formularHolen(19)->ablauf);
	}

	/**
	 * Rueckwaerts, also das zuletzt Geaenderte zuerst: Was dem Fehler am
	 * naechsten steht, wird zuerst geheilt.
	 */
	public function testZurueckgeschriebenWirdRueckwaerts(): void {
		$doppel = $this->doppel();
		(new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(18, 'Warteliste — Anfängerkurs 12./13.09.2026', 40),
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$geaendert = array_values(array_filter(
			$doppel->aufrufe,
			static fn (string $aufruf): bool => str_starts_with($aufruf, 'formularAendern'),
		));
		$this->assertSame(['formularAendern:19', 'formularAendern:18'], $geaendert);
	}

	/**
	 * Was schon auf dem alten Stand steht, wird nicht angefasst.
	 *
	 * Vorher entschied ein Merker aus der Schreibkette. Der konnte nicht
	 * unterscheiden, ob ein Aufruf abgelehnt wurde oder nur seine Antwort
	 * verloren ging - und schrieb im ersten Fall unnoetig zurueck. Misslang
	 * das ebenfalls, meldete die App ein Formular als stehengeblieben, an
	 * dem nie jemand war.
	 */
	public function testWasSchonRichtigStehtWirdNichtAngefasst(): void {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 1_000_000,
				fragen: [new Frage(30, 'teilnahmebedingungen', 'B', self::ALTER_TEXT)]),
		]);

		$stehengeblieben = (new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertSame([], $stehengeblieben);
		$this->assertNotContains('formularAendern:19', $doppel->aufrufe);
		$this->assertNotContains('frageAendern:19/30', $doppel->aufrufe);
	}

	/**
	 * Und andersherum: Ein Formular, das den neuen Stand traegt, geht
	 * zurueck - auch wenn die Schreibkette den Aufruf nie vermerken konnte,
	 * weil seine Antwort verloren ging.
	 */
	public function testEineGeschriebeneTerminzeileGehtZurueck(): void {
		$doppel = $this->doppel();
		(new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertSame(self::ALTER_TEXT,
			$doppel->formularHolen(19)->frageMitNamen('teilnahmebedingungen')?->beschreibung);
	}

	/** Ist die Frage gar nicht mehr da, gibt es nichts zurueckzuschreiben. */
	public function testEineVerschwundeneFrageWirdNichtAngefasst(): void {
		$doppel = new FormulareDoppel([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 6, ablauf: 1_000_000, fragen: []),
		]);

		$stehengeblieben = (new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertSame([], $stehengeblieben);
		$this->assertNotContains('frageAendern:19/30', $doppel->aufrufe);
	}

	/**
	 * Laesst sich der Ist-Stand nicht lesen, gilt das Formular als
	 * stehengeblieben.
	 *
	 * Ohne den Ist-Stand laesst sich nichts sagen - und im Zweifel muss
	 * jemand nachsehen. Die andere Richtung waere die gefaehrliche: Sie
	 * meldete Entwarnung fuer ein Formular, das niemand angesehen hat.
	 */
	public function testEinUnlesbaresFormularGiltAlsStehengeblieben(): void {
		$doppel = $this->doppel();
		$doppel->laessScheitern('formularHolen:19');

		$stehengeblieben = (new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertCount(1, $stehengeblieben);
		$this->assertSame('Anmeldung', $stehengeblieben[0]->beschriftung);
	}

	public function testWasSichNichtHeilenLaesstWirdGemeldet(): void {
		$doppel = $this->doppel();
		$doppel->laessScheitern('formularAendern:19');

		$stehengeblieben = (new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertCount(1, $stehengeblieben);
		$this->assertSame('Anmeldung', $stehengeblieben[0]->beschriftung);
	}

	/**
	 * Beide Werte werden unabhaengig voneinander versucht: Misslingt der
	 * Titel, kann die Terminzeile trotzdem noch zurueckgehen.
	 */
	public function testEinMisslungenerTitelStopptDieTerminzeileNicht(): void {
		$doppel = $this->doppel();
		$doppel->laessScheitern('formularAendern:19');

		(new Zurueckschreiben($doppel, self::BASIS))->stelleWiederHer([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertContains('frageAendern:19/30', $doppel->aufrufe);
	}

	public function testDerHinweisNenntEinenLinkUndKeineNummer(): void {
		$hinweis = (new Zurueckschreiben($this->doppel(), self::BASIS))->hinweis([
			$this->stand(19, 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 30),
		]);

		$this->assertStringContainsString(
			'https://cloud.example.org/apps/forms/editor0000000019/edit', $hinweis);
		$this->assertStringContainsString('Anmeldung', $hinweis);
		$this->assertStringContainsString('alten Termin', $hinweis);
	}
}
