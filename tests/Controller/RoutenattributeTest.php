<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Controller;

use ReflectionClass;
use ReflectionMethod;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;

/**
 * Haelt die Attribute fest, an denen die Sicherheit der Routen haengt.
 *
 * Die Pruefungen stehen nicht im Code, sondern in dem, was an einer Methode
 * steht oder fehlt. Nextclouds Middleware wertet die Attribute aus; PHPUnit
 * sieht davon nichts. Ein hinzugefuegtes NoCSRFRequired oder ein
 * entferntes NoAdminRequired liesse deshalb jeden anderen Test gruen.
 *
 * Die Controller kommen aus dem Verzeichnis und nicht aus einer Liste. Ein
 * neuer Controller faellt so nicht durch.
 */
final class RoutenattributeTest extends TestCase {
	/** Nur diese Routen duerfen ohne CSRF-Token erreichbar sein. Alle lesen nur. */
	private const OHNE_CSRF_PRUEFUNG = [
		'AnlegenController::formular',
		'BenachrichtigenController::verschickt',
		'KursController::zeige',
		'UebersichtController::index',
	];

	/** Nur diese Routen verlangen ein Admin-Konto. */
	private const NUR_FUER_ADMINS = [
		'EinstellungenController::speichere',
	];

	/**
	 * Alle Methoden mit FrontpageRoute, als "Klasse::methode".
	 *
	 * @return array<string, ReflectionMethod>
	 */
	private function routen(): array {
		$routen = [];
		$dateien = glob(__DIR__ . '/../../lib/Controller/*.php') ?: [];

		foreach ($dateien as $datei) {
			$kurzname = basename($datei, '.php');
			$klasse = new ReflectionClass('OCA\\Radfahrschule\\Controller\\' . $kurzname);

			foreach ($klasse->getMethods(ReflectionMethod::IS_PUBLIC) as $methode) {
				if ($methode->getAttributes(FrontpageRoute::class) === []) {
					continue;
				}
				$routen[$kurzname . '::' . $methode->getName()] = $methode;
			}
		}

		ksort($routen);
		return $routen;
	}

	/**
	 * Die Namen der Routen, die ein Attribut tragen.
	 *
	 * @param class-string $attribut
	 * @return list<string>
	 */
	private function routenMit(string $attribut): array {
		$treffer = [];
		foreach ($this->routen() as $name => $methode) {
			if ($methode->getAttributes($attribut) !== []) {
				$treffer[] = $name;
			}
		}
		return $treffer;
	}

	/** Das Verb einer Route, etwa "GET". */
	private function verb(ReflectionMethod $methode): string {
		$route = $methode->getAttributes(FrontpageRoute::class)[0]->newInstance();
		return $route->getVerb();
	}

	/**
	 * Ohne diese Probe liefe jeder Test unten gegen eine leere Liste und
	 * waere gruen.
	 */
	public function testDieRoutenWerdenGefunden(): void {
		$this->assertCount(14, $this->routen());
	}

	public function testNurDieVierLesendenRoutenSindOhneCsrfPruefung(): void {
		$this->assertSame(self::OHNE_CSRF_PRUEFUNG, $this->routenMit(NoCSRFRequired::class));
	}

	/**
	 * Ein Link oder eine Umleitung bringt keinen Token mit. Das gilt nur fuer
	 * GET. Eine POST-Route ohne Pruefung liesse sich von einer fremden Seite
	 * aus ausloesen.
	 */
	public function testKeinePostRouteIstOhneCsrfPruefung(): void {
		$routen = $this->routen();
		foreach ($this->routenMit(NoCSRFRequired::class) as $name) {
			$this->assertSame('GET', $this->verb($routen[$name]), $name);
		}
	}

	/**
	 * Ohne NoAdminRequired verlangt Nextcloud ein Admin-Konto. Fehlt das
	 * Attribut an einer App-Route, kommt die Gruppe nicht mehr hinein. Steht
	 * es an der Einstellungsroute, darf jedes Konto die Zugangsdaten
	 * ueberschreiben.
	 */
	public function testNurDieEinstellungenVerlangenEinAdminKonto(): void {
		$ohneAttribut = array_values(array_diff(
			array_keys($this->routen()),
			$this->routenMit(NoAdminRequired::class),
		));

		$this->assertSame(self::NUR_FUER_ADMINS, $ohneAttribut);
	}

	public function testKeineRouteIstOeffentlich(): void {
		$this->assertSame([], $this->routenMit(PublicPage::class));
	}
}
