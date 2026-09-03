<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Radfahrschule\Controller\UebersichtController;
use OCA\Radfahrschule\Fachlogik\Frist;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Rechte\Verwaltungsrecht;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\INavigationManager;
use OCP\IRequest;
use OCA\Radfahrschule\Tests\Testhilfe\MitTitelmuster;
use PHPUnit\Framework\TestCase;

class UebersichtControllerTest extends TestCase {
	use MitTitelmuster;

	private function controllerMit(
		FormulareDoppel $formulare,
		bool $darfVerwalten = true,
	): UebersichtController {
		$recht = $this->createStub(Verwaltungsrecht::class);
		$recht->method('darfVerwalten')->willReturn($darfVerwalten);

		return new UebersichtController(
			'radfahrschule',
			$this->createStub(IRequest::class),
			$formulare,
			$this->festeZeit(),
			$this->createStub(INavigationManager::class),
			$recht, $this->titelmuster(), new Frist($this->betreiberangaben()), $this->zeitzone());
	}

	// Feste Zeit: Ein Test mit der echten waere an einem bestimmten Datum
	// rot geworden, ohne dass sich eine Zeile aendert.
	private function festeZeit(): ITimeFactory {
		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(
			new DateTimeImmutable('2026-08-25 12:00:00', new DateTimeZone('UTC')),
		);
		return $timeFactory;
	}

	public function testZeigtDieKurseMitZaehlernUndFrist(): void {
		$controller = $this->controllerMit(new FormulareDoppel([
			new Formular(19, 'hash19', 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', '', 6, 0),
			new Formular(18, 'hash18', 'Warteliste — Anfängerkurs 12./13.09.2026', '', 2, 0),
		]));

		$daten = $controller->index()->getParams();

		$this->assertCount(1, $daten['kurse']);
		$this->assertSame('Anfängerkurs 12./13.09.2026', $daten['kurse'][0]['kennung']);
		// Die id verlinkt auf die Detailseite. Ohne sie baut das Template
		// eine Adresse auf null.
		$this->assertSame(19, $daten['kurse'][0]['id']);
		$this->assertSame(6, $daten['kurse'][0]['anmeldungen']);
		$this->assertSame(2, $daten['kurse'][0]['wartende']);
		$this->assertStringContainsString('12.12.2026', $daten['kurse'][0]['frist']);
		$this->assertSame('', $daten['fehler']);
	}

	// Ist Forms nicht erreichbar, zeigt die Seite den Grund - statt einer
	// leeren Tabelle, die aussieht, als gaebe es keine Kurse.
	public function testEinFehlerWirdAngezeigtStattVerschluckt(): void {
		$controller = $this->controllerMit(new FormulareDoppel([], scheitert: true));

		$daten = $controller->index()->getParams();

		$this->assertSame([], $daten['kurse']);
		$this->assertNotSame('', $daten['fehler']);
	}

	/**
	 * Ohne Recht gibt es die Liste gar nicht - auch nicht leer. Wer nicht in
	 * der Gruppe steht und kein Admin ist, soll die Kurstitel und
	 * Zaehlerstaende nicht sehen.
	 */
	public function testOhneRechtBleibtDieUebersichtVerschlossen(): void {
		$controller = $this->controllerMit(new FormulareDoppel([
			new Formular(19, 'hash19', 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', '', 6, 0),
		]), darfVerwalten: false);

		$antwort = $controller->index();

		$this->assertSame('meldung', $antwort->getTemplateName());
		$this->assertArrayNotHasKey('kurse', $antwort->getParams());
	}
}
