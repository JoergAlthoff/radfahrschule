<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Fachlogik\Kurs;
use OCA\Radfahrschule\Fachlogik\Kursliste;
use OCA\Radfahrschule\Fachlogik\Verschiebeplan;
use OCA\Radfahrschule\Fachlogik\Verschiebung;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

final class VerschiebeplanTest extends TestCase {
	use MitTitelmuster;

	private function kurs(
		string $kennung = 'Anfängerkurs 12./13.09.2026',
		int $ablaufAnmeldung = 0,
	): Kurs {
		return Kursliste::ausFormularen([
			new Formular(id: 19, hash: 'editor0000000019',
				titel: 'Radfahrschule Musterstadt — ' . $kennung,
				beschreibung: '', abgaben: 6, ablauf: $ablaufAnmeldung),
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — ' . $kennung,
				beschreibung: '', abgaben: 2, ablauf: 0),
		], $this->titelmuster())[0];
	}

	private function verschiebung(
		string $von = '2026-11-14',
		string $bis = '2026-11-15',
		string $schluss = '2026-11-07',
	): Verschiebung {
		$zone = new DateTimeZone('UTC');
		return new Verschiebung(
			von: new DateTimeImmutable($von, $zone),
			bis: new DateTimeImmutable($bis, $zone),
			anmeldeschluss: new DateTimeImmutable($schluss, $zone),
		);
	}

	private function jetzt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-09-01 10:00:00', new DateTimeZone('UTC'));
	}

	public function testDerPlanTraegtAlteUndNeueTitel(): void {
		$plan = Verschiebeplan::rechne($this->kurs(), $this->verschiebung(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame('Anfängerkurs 12./13.09.2026', $plan->alteKennung);
		$this->assertSame('Anfängerkurs 14./15.11.2026', $plan->neueKennung);
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			$plan->alterTitelAnmeldung);
		$this->assertSame('Radfahrschule Musterstadt — Anfängerkurs 14./15.11.2026',
			$plan->neuerTitelAnmeldung);
		$this->assertSame('Warteliste — Anfängerkurs 14./15.11.2026',
			$plan->neuerTitelWarteliste);
	}

	/**
	 * Die Kursart wird UEBERNOMMEN und nicht neu gesetzt. Ein fest
	 * eingetragener Wert machte aus jedem verschobenen Kurs still einen
	 * Anfaengerkurs - und die Formulare der anderen Reihe paarten sich nicht
	 * mehr.
	 */
	/**
	 * Die "bisher"-Titel muessen aus den vorhandenen Formularen kommen,
	 * nicht aus der Kennung.
	 *
	 * Gebaut wurden sie als Praefix + Kennung. Ist die Anmeldung in
	 * Nextcloud umbenannt worden und deshalb aus dem Paar gefallen, stand
	 * ihre Zeile trotzdem in der Kontrollseite - waehrend
	 * Verschiebekette::leseAllesEin nur ueber Kurs::zeilen() laeuft und sie
	 * gar nicht anfasst. Der Bediener bestaetigte eine Aenderung an zwei
	 * Formularen und bekam eine an einem.
	 */
	public function testDerAlteTitelKommtAusDemFormular(): void {
		$nurWarteliste = Kursliste::ausFormularen([
			new Formular(id: 18, hash: 'editor0000000018',
				titel: 'Warteliste — Anfängerkurs 12./13.09.2026',
				beschreibung: '', abgaben: 2, ablauf: 0),
		], $this->titelmuster())[0];

		$plan = Verschiebeplan::rechne(
			$nurWarteliste, $this->verschiebung(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame('', $plan->alterTitelAnmeldung,
			'Es gibt keine Anmeldung - also gibt es auch keinen alten Titel.');
		$this->assertSame(
			'Warteliste — Anfängerkurs 12./13.09.2026', $plan->alterTitelWarteliste);
	}

	public function testDieKursartBleibtErhalten(): void {
		$plan = Verschiebeplan::rechne(
			$this->kurs('Fortgeschrittene 12./13.09.2026'),
			$this->verschiebung(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame('Fortgeschrittene 14./15.11.2026', $plan->neueKennung);
	}

	/**
	 * Der Termin ist das letzte Feld der Kennung; alles davor ist die
	 * Kursart. Steht kein Leerzeichen darin, gibt es keine Art.
	 */
	public function testEineKennungOhneArtWirdZumTerminAllein(): void {
		$plan = Verschiebeplan::rechne(
			$this->kurs('12./13.09.2026'), $this->verschiebung(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame('14./15.11.2026', $plan->neueKennung);
	}

	/**
	 * Abgelehnt wird nur, wenn sich WIRKLICH nichts aendert. Der Kurs traegt
	 * deshalb schon den Anmeldeschluss, den die Verschiebung schicken wuerde
	 * - sonst waere sehr wohl etwas zu tun.
	 */
	public function testDerselbeTerminUndDerselbeSchlussWerdenAbgelehnt(): void {
		$zone = new DateTimeZone('UTC');
		$kurs = $this->kurs(
			ablaufAnmeldung: $this->ablauf()->fuerAnmeldung(new DateTimeImmutable('2026-09-05', $zone)));

		$this->expectException(InvalidArgumentException::class);

		Verschiebeplan::rechne(
			$kurs,
			$this->verschiebung('2026-09-12', '2026-09-13', '2026-09-05'),
			$this->jetzt(), $this->titelmuster(), $this->ablauf());
	}

	/**
	 * Der Anmeldeschluss allein muss sich aendern lassen.
	 *
	 * Die Abbruchbedingung verglich nur die Kennung, und die haengt allein
	 * an von/bis. Wer die Anmeldefrist verlaengern wollte, bekam "Der Kurs
	 * steht bereits auf diesem Termin" - obwohl das Formular alle drei
	 * Felder als required anbietet und Verschiebekette::neuerAblaufFuer den
	 * neuen Wert sehr wohl schreiben wuerde. Die Frist war damit nach dem
	 * Anlegen unveraenderlich.
	 */
	public function testDerAnmeldeschlussAlleinLaesstSichAendern(): void {
		$zone = new DateTimeZone('UTC');
		$kurs = $this->kurs(
			ablaufAnmeldung: $this->ablauf()->fuerAnmeldung(new DateTimeImmutable('2026-09-05', $zone)));

		$plan = Verschiebeplan::rechne(
			$kurs,
			// Kurstage unveraendert, nur der Schluss rueckt vor.
			$this->verschiebung('2026-09-12', '2026-09-13', '2026-09-10'),
			$this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame(
			$this->ablauf()->fuerAnmeldung(new DateTimeImmutable('2026-09-10', $zone)),
			$plan->ablaufAnmeldung);
		$this->assertSame($kurs->kennung, $plan->neueKennung);
	}

	/**
	 * Dieselben drei Regeln wie beim Anlegen. Liefe eine Seite der anderen
	 * davon, verboete sie, was die andere erlaubt.
	 */
	public function testEinTerminInDerVergangenheitWirdAbgelehnt(): void {
		$this->expectException(InvalidArgumentException::class);

		Verschiebeplan::rechne(
			$this->kurs(),
			$this->verschiebung('2025-11-14', '2025-11-15', '2025-11-07'),
			$this->jetzt(), $this->titelmuster(), $this->ablauf());
	}

	public function testEinAnmeldeschlussNachDemBeginnWirdAbgelehnt(): void {
		$this->expectException(InvalidArgumentException::class);

		Verschiebeplan::rechne(
			$this->kurs(),
			$this->verschiebung('2026-11-14', '2026-11-15', '2026-11-20'),
			$this->jetzt(), $this->titelmuster(), $this->ablauf());
	}

	/**
	 * Die Ablaufzeiten kommen aus derselben Rechnung wie beim Anlegen: die
	 * Anmeldung zum Anmeldeschluss, die Warteliste erst am Kurstag.
	 */
	public function testDieWartelisteLaeuftLaengerAlsDieAnmeldung(): void {
		$plan = Verschiebeplan::rechne($this->kurs(), $this->verschiebung(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertGreaterThan($plan->ablaufAnmeldung, $plan->ablaufWarteliste);
	}

	/** Fuer die versteckten Felder der Kontrollseite. */
	public function testDieTermineStehenAuchInIso(): void {
		$plan = Verschiebeplan::rechne($this->kurs(), $this->verschiebung(), $this->jetzt(), $this->titelmuster(), $this->ablauf());

		$this->assertSame('2026-11-14', $plan->vonIso());
		$this->assertSame('2026-11-15', $plan->bisIso());
		$this->assertSame('2026-11-07', $plan->anmeldeschlussIso());
	}
}
