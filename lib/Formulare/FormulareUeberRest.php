<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

use JsonException;
use Throwable;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Spricht die Forms-REST-API der eigenen Instanz.
 *
 * Der Weg ueber HTTP statt ueber die PHP-Klassen von Forms ist Absicht:
 * Forms hat keine oeffentliche Schnittstelle fuer andere Apps, seine
 * Service-Klassen sind Interna ohne Zusage. Die REST-API dagegen ist
 * versioniert.
 */
final readonly class FormulareUeberRest implements Formulare {
	private const LISTE = '/ocs/v2.php/apps/forms/api/v3/forms';
	private const EINZELN = '/ocs/v2.php/apps/forms/api/v3/forms/%d';
	private const KLON = '/ocs/v2.php/apps/forms/api/v3/forms?fromId=%d';
	private const FRAGE = '/ocs/v2.php/apps/forms/api/v3/forms/%d/questions/%d';
	private const FREIGABEN = '/ocs/v2.php/apps/forms/api/v3/forms/%d/shares';

	/**
	 * Was gesagt wird, wenn sich nichts Genaueres sagen laesst.
	 *
	 * Er nennt Adresse UND Zugangsdaten, weil hier wirklich keines von
	 * beiden ausgeschlossen ist.
	 */
	private const ALLGEMEIN = 'Nextcloud Forms antwortet nicht. Bitte Adresse '
		. 'und Zugangsdaten in den Einstellungen prüfen; Einzelheiten stehen '
		. 'im Nextcloud-Protokoll.';

	public function __construct(
		private IClientService $clientService,
		private Zugangsdaten $zugangsdaten,
		private LoggerInterface $logger,
	) {
	}

	public function alleEigenen(): array {
		$daten = $this->rufe('get', self::LISTE . '?type=owned');

		$formulare = [];
		foreach ($daten as $eintrag) {
			$formulare[] = Formular::ausAntwort((array)$eintrag);
		}
		return $formulare;
	}

	public function formularHolen(int $id): Formular {
		return $this->formularAus(
			$this->rufe('get', sprintf(self::EINZELN, $id)),
			sprintf('das Holen von Formular %d', $id));
	}

	public function formularKlonen(int $vorlageId): Formular {
		return $this->formularAus(
			$this->rufe('post', sprintf(self::KLON, $vorlageId)),
			sprintf('das Klonen von Vorlage %d', $vorlageId));
	}

	/**
	 * Baut ein Formular und prueft, dass wirklich eines kam.
	 *
	 * datenAus gibt [] zurueck, wenn ocs.data kein Objekt ist. Diese
	 * Nachsicht ist fuer DELETE gebaut, galt aber fuer jeden Aufruf - und
	 * daraus entstand ein Formular mit id 0 und leerem Hash. Die
	 * Schreibkette patcht dann Formular 0, das Zurueckrollen loescht
	 * Formular 0, und der Hinweistext nennt eine Adresse mit leerem Hash,
	 * waehrend der echte Klon unauffindbar in Nextcloud stehenbleibt.
	 *
	 * @param array<mixed> $daten
	 * @throws FormulareNichtErreichbar
	 */
	private function formularAus(array $daten, string $wozu): Formular {
		/** @var array<string, mixed> $daten */
		$formular = Formular::ausAntwort($daten);
		if ($formular->id === 0) {
			throw new FormulareNichtErreichbar(sprintf(
				'Forms lieferte für %s kein Formular zurück.', $wozu));
		}
		return $formular;
	}

	public function formularAendern(int $id, array $felder): void {
		$this->rufe('patch', sprintf(self::EINZELN, $id), ['keyValuePairs' => $felder]);
	}

	public function frageAendern(int $formularId, int $frageId, array $felder): void {
		$this->rufe('patch', sprintf(self::FRAGE, $formularId, $frageId),
			['keyValuePairs' => $felder]);
	}

	public function formularLoeschen(int $id): void {
		$this->rufe('delete', sprintf(self::EINZELN, $id));
	}

	public function linkFreigabeAnlegen(int $formularId): string {
		$daten = $this->rufe('post', sprintf(self::FREIGABEN, $formularId), [
			'shareType' => Freigabe::TYP_LINK,
			'shareWith' => '',
			'permissions' => ['submit'],
		]);

		$hash = (string)($daten['shareWith'] ?? '');
		if ($hash === '') {
			throw new FormulareNichtErreichbar(sprintf(
				'Die Freigabe für Formular %d kam ohne Hash zurück.', $formularId));
		}
		return $hash;
	}

	public function gruppenFreigabeAnlegen(int $formularId, string $gruppe): void {
		$this->rufe('post', sprintf(self::FREIGABEN, $formularId), [
			'shareType' => Freigabe::TYP_GRUPPE,
			'shareWith' => $gruppe,
			'permissions' => ['submit', 'results', 'results_delete'],
		]);
	}

	/**
	 * Ein Aufruf gegen die OCS-API, ausgepackt bis auf ocs.data.
	 *
	 * @param array<string, mixed>|null $payload
	 * @return array<mixed>
	 * @throws FormulareNichtErreichbar
	 */
	private function rufe(string $methode, string $pfad, ?array $payload = null): array {
		if (!$this->zugangsdaten->sindVollstaendig()) {
			throw new FormulareNichtErreichbar(
				'Die Zugangsdaten sind unvollständig. Sie stehen in den '
				. 'Administrationseinstellungen unter „Radfahrschule".',
			);
		}

		$optionen = [
			'headers' => [
				// Pflicht. Ohne diesen Header antwortet Nextcloud mit einer
				// Weiterleitung zur Anmeldeseite statt mit JSON.
				'OCS-APIRequest' => 'true',
				'Accept' => 'application/json',
			],
			'auth' => [$this->zugangsdaten->benutzer(), $this->zugangsdaten->appPasswort()],
			'timeout' => 30,
		];
		if ($payload !== null) {
			$optionen['json'] = $payload;
		}

		$client = $this->clientService->newClient();
		$url = $this->zugangsdaten->basisUrl() . $pfad;

		try {
			$response = match ($methode) {
				'get' => $client->get($url, $optionen),
				'post' => $client->post($url, $optionen),
				'patch' => $client->patch($url, $optionen),
				'delete' => $client->delete($url, $optionen),
			};
		} catch (Throwable $fehler) {
			// Die Meldung des Clients trug die interne Adresse, den vollen
			// API-Pfad und einen Link auf curl.se - auf einer Seite, die ein
			// Vereinsmitglied sieht. Geholfen hat sie dabei niemandem.
			// Die Ursache geht als Text ins Log, nicht als Objekt. Ein
			// Throwable im Kontext laesst Nextcloud dessen Trace
			// serialisieren - und die traegt bei
			// zend.exception_ignore_args=Off die Argumentwerte, also das
			// Options-Array mit 'auth' => [Benutzer, App-Passwort].
			// Nextclouds eigene Redigierung greift dort nicht: Sie
			// vergleicht mit === gegen das Array, das der Client bekam,
			// Guzzle baut sich aber ein neues.
			$this->logger->error('Forms antwortete nicht.', [
				'app' => 'radfahrschule',
				'methode' => $methode,
				'pfad' => $pfad,
				'ursache' => $fehler::class . ': ' . $fehler->getMessage(),
			]);

			throw new FormulareNichtErreichbar(
				$this->grundFuer($client, $fehler), previous: $fehler);
		}

		return $this->datenAus($response->getBody());
	}

	/**
	 * Sagt, WAS nicht stimmt, soweit die Antwort es hergibt.
	 *
	 * Ein Satz, der Adresse und Zugangsdaten zugleich nennt, entscheidet
	 * nichts: Wer sich im Kontonamen vertippt, sucht danach auch die
	 * Adresse ab. Der HTTP-Status weiss es genauer. Ein falsches Konto gibt
	 * 401, ein Pfad, den es nicht gibt, 404.
	 *
	 * getResponseFromThrowable wirft die Ausnahme laut Schnittstelle
	 * weiter, wenn keine HTTP-Antwort dabei war. Das ist kein Sonderfall,
	 * sondern der haeufigste: Ein unbekannter Name und eine abgewiesene
	 * Verbindung kommen nie bis zu einem Status. Dann bleibt es beim
	 * allgemeinen Satz.
	 *
	 * Ueber 403 und 5xx wird NICHTS behauptet. Ein 500 heisst, dass Forms
	 * da ist und nicht kann - daraus folgt fuer die Zugangsdaten nichts.
	 */
	private function grundFuer(IClient $client, Throwable $fehler): string {
		try {
			$status = $client->getResponseFromThrowable($fehler)->getStatusCode();
		} catch (Throwable) {
			return self::ALLGEMEIN;
		}

		return match ($status) {
			401 => 'Das Dienstkonto oder das App-Passwort stimmt nicht. Beides '
				. 'steht in den Einstellungen. Das App-Passwort stellt '
				. 'Nextcloud beim Dienstkonto unter „Sicherheit" aus.',
			404 => 'Unter dieser Adresse antwortet Forms nicht. Bitte die '
				. 'Adresse der Instanz in den Einstellungen prüfen. Und ob '
				. 'die App „Formulare" dort läuft.',
			default => self::ALLGEMEIN,
		};
	}

	/**
	 * Packt den OCS-Umschlag aus.
	 *
	 * Ein DELETE liefert unter data auch mal eine blosse Zahl statt eines
	 * Objekts. Das ist kein Fehler - der Aufrufer liest davon nichts.
	 *
	 * @return array<mixed>
	 * @throws FormulareNichtErreichbar
	 */
	private function datenAus(string $koerper): array {
		try {
			$gelesen = json_decode($koerper, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException $fehler) {
			throw new FormulareNichtErreichbar(
				'Die Antwort von Forms war kein JSON.',
				previous: $fehler,
			);
		}

		if (!is_array($gelesen) || !array_key_exists('ocs', $gelesen)) {
			throw new FormulareNichtErreichbar('Die Antwort von Forms trug keinen OCS-Umschlag.');
		}

		// Der Umschlag traegt einen eigenen Status. Steht er nicht auf "ok",
		// ist der Aufruf gescheitert, auch wenn HTTP 200 zurueckkam. Fehlt
		// der Schluessel ganz, wird nichts behauptet - dann zaehlt allein
		// der HTTP-Code.
		$status = $gelesen['ocs']['meta']['status'] ?? null;
		if ($status !== null && $status !== 'ok') {
			throw new FormulareNichtErreichbar(
				'Forms hat den Aufruf abgelehnt. Einzelheiten stehen im '
				. 'Nextcloud-Protokoll.');
		}

		$daten = $gelesen['ocs']['data'] ?? null;
		if (!is_array($daten)) {
			return [];
		}

		return $daten;
	}
}
