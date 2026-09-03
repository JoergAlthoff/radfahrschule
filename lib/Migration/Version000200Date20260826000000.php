<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Migration;

use Closure;
use Override;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Legt die Tabelle des Vorgangsprotokolls an.
 *
 * Der Name der Klasse traegt die App-Version, ab der sie laeuft: 0.2.0
 * ergibt 000200. Nextcloud fuehrt Migrationen bis zur installierten Version
 * aus - steht in info.xml eine kleinere Nummer, laeuft diese Datei nie.
 */
class Version000200Date20260826000000 extends SimpleMigrationStep {
	public const TABELLE = 'radfahrschule_vorgang';

	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABELLE)) {
			return null;
		}

		$tabelle = $schema->createTable(self::TABELLE);

		$tabelle->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);
		// Der Zeitpunkt in UTC. Nicht die Ortszeit: Zwei Zeilen aus
		// verschiedenen Zonen liessen sich nicht mehr ordnen.
		$tabelle->addColumn('zeitpunkt', Types::DATETIME_IMMUTABLE, [
			'notnull' => true,
		]);
		// Der Anker beim Suchen, etwa "kurs.angelegt".
		$tabelle->addColumn('vorgang', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		// Der angemeldete Mensch, nicht das Nextcloud-Konto. Die Laenge ist
		// die einer Nextcloud-Benutzerkennung.
		$tabelle->addColumn('benutzer', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		// Der gemeinsame Rest beider Formulartitel. Immer OHNE Praefix -
		// sonst liessen sich Anlegen und Loeschen nicht mehr paaren.
		$tabelle->addColumn('kennung', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		// In ISO, damit sich danach sortieren laesst.
		$tabelle->addColumn('kurstag', Types::STRING, [
			'notnull' => false,
			'length' => 10,
		]);
		$tabelle->addColumn('anmeldung_id', Types::INTEGER, ['notnull' => false]);
		$tabelle->addColumn('warteliste_id', Types::INTEGER, ['notnull' => false]);
		$tabelle->addColumn('plaetze', Types::INTEGER, ['notnull' => false]);
		// Eine ZAHL, kein Personenbezug. Die Anmeldedaten selbst holt die
		// App nie. Gefuellt wird die Spalte nur beim Loeschen.
		$tabelle->addColumn('anmeldungen', Types::INTEGER, ['notnull' => false]);
		$tabelle->addColumn('grund', Types::TEXT, ['notnull' => false]);

		$tabelle->setPrimaryKey(['id']);
		// Gesucht wird nach Vorgangsart und nach Zeit - "was ist zuletzt
		// abgebrochen" ist die Frage, fuer die es das Protokoll gibt.
		// Indexnamen sind instanzweit eindeutig, daher das Kuerzel rf_.
		$tabelle->addIndex(['vorgang'], 'rf_vorgang_art');
		$tabelle->addIndex(['zeitpunkt'], 'rf_vorgang_zeit');

		return $schema;
	}
}
