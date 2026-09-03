<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Protokoll;

use Throwable;
use OCA\Radfahrschule\Migration\Version000200Date20260826000000;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Schreibt eine Protokollzeile in die eigene Tabelle.
 *
 * Ein Fehlschlag hier darf den Vorgang nicht kippen: Der Kurs steht dann
 * bereits in Nextcloud, und eine Ausnahme machte aus einem gelungenen Lauf
 * eine Fehlermeldung. Er wandert stattdessen ins Nextcloud-Log - dort faellt
 * er einer Administration auf, ohne den Menschen davor zu beschaeftigen.
 */
final readonly class ProtokollInDerDatenbank implements Protokoll {
	public function __construct(
		private IDBConnection $dbConnection,
		private LoggerInterface $logger,
	) {
	}

	public function schreibe(Vorgang $vorgang): void {
		try {
			$abfrage = $this->dbConnection->getQueryBuilder();
			$abfrage->insert(Version000200Date20260826000000::TABELLE)
				->values([
					'zeitpunkt' => $abfrage->createNamedParameter(
						$vorgang->zeitpunkt, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
					'vorgang' => $abfrage->createNamedParameter($vorgang->vorgang),
					'benutzer' => $abfrage->createNamedParameter($vorgang->benutzer),
					'kennung' => $abfrage->createNamedParameter($vorgang->kennung),
					'kurstag' => $abfrage->createNamedParameter($vorgang->kurstag),
					'anmeldung_id' => $abfrage->createNamedParameter($vorgang->anmeldungId),
					'warteliste_id' => $abfrage->createNamedParameter($vorgang->wartelisteId),
					'plaetze' => $abfrage->createNamedParameter($vorgang->plaetze),
					'anmeldungen' => $abfrage->createNamedParameter($vorgang->anmeldungen),
					'grund' => $abfrage->createNamedParameter($vorgang->grund),
				]);
			$abfrage->executeStatement();
		} catch (Throwable $fehler) {
			$this->logger->error('Die Protokollzeile ließ sich nicht schreiben.', [
				'app' => 'radfahrschule',
				'vorgang' => $vorgang->vorgang,
				'kennung' => $vorgang->kennung,
				'exception' => $fehler,
			]);
		}
	}
}
