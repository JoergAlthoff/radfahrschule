<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Testhilfe;

use OCA\Radfahrschule\Einstellungen\Betreiberangaben;
use OCA\Radfahrschule\Fachlogik\Ablauf;
use OCA\Radfahrschule\Fachlogik\Titelmuster;
use OCA\Radfahrschule\Fachlogik\Zeitzone;
use OCP\IAppConfig;

/**
 * Die Dienste, die an den Angaben des Betreibers haengen - fuer Tests, die
 * Titel bauen, Ablaufzeitpunkte rechnen oder einen Kalendertag ablesen.
 *
 * Die Vorgabewerte sind die einer eingerichteten Instanz. Wer andere
 * braucht - etwa um zu pruefen, dass ein anderer Betreiber andere Titel
 * bekommt - gibt sie mit.
 */
trait MitTitelmuster {
	/** @param array<string, string> $werte */
	protected function titelmuster(array $werte = []): Titelmuster {
		return new Titelmuster($this->betreiberangaben($werte));
	}

	/** @param array<string, string> $werte */
	protected function zeitzone(array $werte = []): Zeitzone {
		return new Zeitzone($this->betreiberangaben($werte));
	}

	/** @param array<string, string> $werte */
	protected function ablauf(array $werte = []): Ablauf {
		return new Ablauf($this->zeitzone($werte));
	}

	/** @param array<string, string> $werte */
	protected function betreiberangaben(array $werte = []): Betreiberangaben {
		$vollstaendig = array_merge([
			'name' => 'Radfahrschule Musterstadt',
			'kursart' => 'Anfängerkurs',
		], $werte);

		$konfig = $this->createStub(IAppConfig::class);
		$konfig->method('getValueString')
			->willReturnCallback(
				static fn (string $app, string $schluessel, string $vorgabe = '')
					=> $vollstaendig[$schluessel] ?? $vorgabe,
			);

		return new Betreiberangaben($konfig);
	}
}
