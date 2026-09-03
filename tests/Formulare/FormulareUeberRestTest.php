<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Formulare;

use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Formulare\FormulareNichtErreichbar;
use OCA\Radfahrschule\Formulare\FormulareUeberRest;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;

class FormulareUeberRestTest extends TestCase {
	private function antwortMit(string $koerper, int $status = 200): IResponse {
		$response = $this->createStub(IResponse::class);
		$response->method('getBody')->willReturn($koerper);
		$response->method('getStatusCode')->willReturn($status);
		return $response;
	}

	/** @param array{url: string, optionen: array}|null $gesehen */
	private function dienstMit(IResponse $response, ?array &$gesehen = null): IClientService {
		$client = $this->createStub(IClient::class);
		$merker = function (string $url, array $optionen) use ($response, &$gesehen): IResponse {
			$gesehen = ['url' => $url, 'optionen' => $optionen];
			return $response;
		};
		$client->method('get')->willReturnCallback($merker);
		$client->method('post')->willReturnCallback($merker);
		$client->method('delete')->willReturnCallback($merker);

		$dienst = $this->createStub(IClientService::class);
		$dienst->method('newClient')->willReturn($client);
		return $dienst;
	}

	/**
	 * Merkt sich, was geloggt wurde.
	 *
	 * Die technische Meldung darf die Seite nicht mehr erreichen - sie muss
	 * aber irgendwo landen, sonst steht ein Admin vor "antwortet nicht" und
	 * hat nichts zum Nachsehen.
	 *
	 * @param list<array{string, array<string, mixed>}> $gelogged
	 */
	private function loggerDerMitschreibt(array &$gelogged): LoggerInterface {
		$logger = $this->createStub(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string|Stringable $meldung, array $kontext = []) use (&$gelogged): void {
				$gelogged[] = [(string)$meldung, $kontext];
			},
		);
		return $logger;
	}

	private function logger(): LoggerInterface {
		return $this->createStub(LoggerInterface::class);
	}

	private function zugangsdaten(): Zugangsdaten {
		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('basisUrl')->willReturn('https://cloud.beispiel.invalid');
		$zugangsdaten->method('benutzer')->willReturn('radfahrschule');
		$zugangsdaten->method('appPasswort')->willReturn('geheim');
		$zugangsdaten->method('sindVollstaendig')->willReturn(true);
		return $zugangsdaten;
	}

	public function testLiestDieListeAusDerOcsAntwort(): void {
		$koerper = json_encode([
			'ocs' => [
				'meta' => ['status' => 'ok', 'statuscode' => 200],
				'data' => [
					['id' => 19, 'hash' => 'hash19', 'title' => 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026', 'submissionCount' => 6],
					['id' => 18, 'hash' => 'hash18', 'title' => 'Warteliste — Anfängerkurs 12./13.09.2026', 'submissionCount' => 2],
				],
			],
		], JSON_THROW_ON_ERROR);

		$anbindung = new FormulareUeberRest($this->dienstMit($this->antwortMit($koerper)), $this->zugangsdaten(), $this->logger());
		$formulare = $anbindung->alleEigenen();

		$this->assertCount(2, $formulare);
		$this->assertSame(19, $formulare[0]->id);
		$this->assertSame(6, $formulare[0]->abgaben);
	}

	// Der Header OCS-APIRequest ist Pflicht. Ohne ihn antwortet Nextcloud
	// mit einer Weiterleitung zur Anmeldeseite statt mit JSON.
	public function testSendetDieOcsKopfzeilenUndDenZugang(): void {
		$koerper = json_encode(['ocs' => ['meta' => ['status' => 'ok'], 'data' => []]], JSON_THROW_ON_ERROR);
		$gesehen = null;

		$anbindung = new FormulareUeberRest($this->dienstMit($this->antwortMit($koerper), $gesehen), $this->zugangsdaten(), $this->logger());
		$anbindung->alleEigenen();

		$this->assertStringContainsString('/ocs/v2.php/apps/forms/api/v3/forms', $gesehen['url']);
		$this->assertSame('true', $gesehen['optionen']['headers']['OCS-APIRequest']);
		$this->assertSame(['radfahrschule', 'geheim'], $gesehen['optionen']['auth']);
	}

	public function testKaputteAntwortWirdZuEinemLesbarenFehler(): void {
		$anbindung = new FormulareUeberRest($this->dienstMit($this->antwortMit('kein json')), $this->zugangsdaten(), $this->logger());

		$this->expectException(FormulareNichtErreichbar::class);
		$anbindung->alleEigenen();
	}

	/**
	 * Gueltiges JSON, aber kein Objekt: Nextcloud antwortet so, wenn statt
	 * der API eine Fehlerseite oder eine blosse Zahl zurueckkommt.
	 *
	 * Der Test darueber deckt das nicht ab - er trifft den JSON-Fehler und
	 * kommt bis zur Umschlag-Pruefung gar nicht.
	 */
	public function testEineAntwortOhneObjektWirdAbgewiesen(): void {
		$anbindung = new FormulareUeberRest($this->dienstMit($this->antwortMit('42')), $this->zugangsdaten(), $this->logger());

		$this->expectException(FormulareNichtErreichbar::class);
		$anbindung->alleEigenen();
	}

	/**
	 * Ein Objekt ohne OCS-Umschlag. Das kommt vom Anmeldeformular zurueck,
	 * wenn die Kopfzeile OCS-APIRequest fehlt - dann leitet Nextcloud um
	 * statt zu antworten.
	 *
	 * Zusammen mit dem Test darueber haelt dieser das ODER in der Pruefung:
	 * Jede der beiden Bedingungen allein muss reichen.
	 */
	public function testEineAntwortOhneOcsUmschlagWirdAbgewiesen(): void {
		$anbindung = new FormulareUeberRest(
			$this->dienstMit($this->antwortMit('{"message":"bitte anmelden"}')),
			$this->zugangsdaten(),
			$this->logger(),
		);

		$this->expectException(FormulareNichtErreichbar::class);
		$anbindung->alleEigenen();
	}

	/**
	 * Die Meldung des HTTP-Clients gehoert ins Log, nicht auf die Seite.
	 *
	 * Sie traegt die interne Adresse, den vollen API-Pfad und einen Link
	 * auf curl.se. Auf einer Seite, die ein Vereinsmitglied sieht, hilft
	 * das niemandem.
	 */
	public function testDieTechnischeMeldungGehtInsLogUndNichtAufDieSeite(): void {
		$gelogged = [];
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(
			new RuntimeException('cURL error 6: Could not resolve host: geheim.intern'));
		$dienst = $this->createStub(IClientService::class);
		$dienst->method('newClient')->willReturn($client);

		$anbindung = new FormulareUeberRest(
			$dienst, $this->zugangsdaten(), $this->loggerDerMitschreibt($gelogged));

		try {
			$anbindung->alleEigenen();
			$this->fail('Es haette FormulareNichtErreichbar kommen muessen.');
		} catch (FormulareNichtErreichbar $fehler) {
			$this->assertStringNotContainsString('geheim.intern', $fehler->getMessage());
			$this->assertStringNotContainsString('cURL', $fehler->getMessage());
			$this->assertStringNotContainsString('curl.se', $fehler->getMessage());
			// Die Seite soll sagen, wo man nachsieht.
			$this->assertStringContainsString('Einstellungen', $fehler->getMessage());
		}

		$this->assertCount(1, $gelogged);
		$this->assertSame('radfahrschule', $gelogged[0][1]['app']);
		// Die Ursache steht als Text da - sonst waere sie ganz weg.
		$this->assertSame(
			RuntimeException::class . ': cURL error 6: Could not resolve host: geheim.intern',
			$gelogged[0][1]['ursache'],
		);
	}

	/**
	 * Das Exception-Objekt selbst darf nicht ins Log.
	 *
	 * Im Container steht zend.exception_ignore_args auf Off, die Trace
	 * traegt also die Argumentwerte. Nextclouds ExceptionSerializer
	 * redigiert zwar OC\Http\Client\Client::get, die Ausnahme entsteht
	 * aber tiefer: GuzzleHttp\Client::request haelt ein NEU gebautes
	 * Options-Array, und darin steht weiter das App-Passwort. Der
	 * Vergleich in removeValuesFromArgs laeuft mit === gegen das alte
	 * Array und trifft das neue nicht.
	 *
	 * Ergebnis waere das App-Passwort im Klartext in nextcloud.log - das
	 * jeder Admin im Browser herunterladen kann, obwohl Zugangsdaten es
	 * eigens mit sensitive: true ablegt.
	 */
	public function testKeinExceptionObjektImLog(): void {
		$gelogged = [];
		$client = $this->createStub(IClient::class);
		$client->method('get')->willThrowException(new RuntimeException('irgendwas'));
		$dienst = $this->createStub(IClientService::class);
		$dienst->method('newClient')->willReturn($client);

		$anbindung = new FormulareUeberRest(
			$dienst, $this->zugangsdaten(), $this->loggerDerMitschreibt($gelogged));

		try {
			$anbindung->alleEigenen();
		} catch (FormulareNichtErreichbar) {
			// erwartet
		}

		$this->assertCount(1, $gelogged);
		foreach ($gelogged[0][1] as $schluessel => $wert) {
			$this->assertNotInstanceOf(
				Throwable::class,
				$wert,
				"Der Logkontext traegt unter '$schluessel' ein Throwable. "
				. 'Nextcloud serialisiert dessen Trace samt Argumentwerten, '
				. 'und darin steht das App-Passwort.',
			);
		}
	}

	/**
	 * Ein Formular ohne id darf nicht entstehen.
	 *
	 * datenAus gibt [] zurueck, wenn ocs.data kein Objekt ist - eine
	 * Nachsicht, die fuer DELETE gebaut wurde und fuer JEDEN Aufruf galt.
	 * Daraus wurde ein Formular mit id 0 und leerem Hash: Die Schreibkette
	 * patcht dann Formular 0, das Zurueckrollen loescht Formular 0, und der
	 * Hinweistext nennt eine Adresse mit leerem Hash - waehrend der echte
	 * Klon unauffindbar in Nextcloud stehenbleibt.
	 */
	public function testEinFormularOhneIdWirdAbgewiesen(): void {
		$koerper = (string)json_encode(['ocs' => ['data' => 42]]);
		$anbindung = new FormulareUeberRest(
			$this->dienstMit($this->antwortMit($koerper)), $this->zugangsdaten(), $this->logger());

		$this->expectException(FormulareNichtErreichbar::class);
		$anbindung->formularHolen(19);
	}

	public function testEinKlonOhneIdWirdAbgewiesen(): void {
		$koerper = (string)json_encode(['ocs' => ['data' => []]]);
		$anbindung = new FormulareUeberRest(
			$this->dienstMit($this->antwortMit($koerper)), $this->zugangsdaten(), $this->logger());

		$this->expectException(FormulareNichtErreichbar::class);
		$anbindung->formularKlonen(24);
	}

	/**
	 * Die Nachsicht bleibt, wo sie gemeint war: Ein DELETE liefert unter
	 * data auch mal eine blosse Zahl, und der Aufrufer liest davon nichts.
	 */
	public function testEinDeleteDarfWeiterhinEineZahlLiefern(): void {
		$koerper = (string)json_encode(['ocs' => ['data' => 42]]);
		$anbindung = new FormulareUeberRest(
			$this->dienstMit($this->antwortMit($koerper)), $this->zugangsdaten(), $this->logger());

		$anbindung->formularLoeschen(19);

		$this->expectNotToPerformAssertions();
	}

	/**
	 * Der OCS-Umschlag traegt einen eigenen Status. Steht er nicht auf "ok",
	 * ist der Aufruf gescheitert - auch wenn HTTP 200 zurueckkam.
	 */
	public function testEinFehlerStatusImUmschlagWirdAbgewiesen(): void {
		$koerper = (string)json_encode([
			'ocs' => ['meta' => ['status' => 'failure'], 'data' => []],
		]);
		$anbindung = new FormulareUeberRest(
			$this->dienstMit($this->antwortMit($koerper)), $this->zugangsdaten(), $this->logger());

		$this->expectException(FormulareNichtErreichbar::class);
		$anbindung->alleEigenen();
	}

	public function testOhneVollstaendigeZugangsdatenGarKeinAufruf(): void {
		$zugangsdaten = $this->createStub(Zugangsdaten::class);
		$zugangsdaten->method('sindVollstaendig')->willReturn(false);

		$dienst = $this->createMock(IClientService::class);
		$dienst->expects($this->never())->method('newClient');

		$anbindung = new FormulareUeberRest($dienst, $zugangsdaten, $this->logger());

		$this->expectException(FormulareNichtErreichbar::class);
		$anbindung->alleEigenen();
	}

	/**
	 * Ein Client-Doppel, das jede Methode annimmt und festhaelt, was es
	 * gesehen hat.
	 *
	 * @param array<string, mixed> $antwort was Forms zurueckgibt
	 * @param array<string, mixed>|null $gesehen wird mit Methode, Adresse und Koerper gefuellt
	 */
	private function restMit(array $antwort, ?array &$gesehen = null): FormulareUeberRest {
		$response = $this->antwortMit(json_encode($antwort, JSON_THROW_ON_ERROR));

		$client = $this->createStub(IClient::class);
		foreach (['get', 'post', 'patch', 'delete'] as $methode) {
			$client->method($methode)->willReturnCallback(
				function (string $url, array $optionen) use ($methode, $response, &$gesehen): IResponse {
					$gesehen = [
						'methode' => $methode,
						'url' => $url,
						'body' => $optionen['json'] ?? null,
					];
					return $response;
				},
			);
		}

		$clientService = $this->createStub(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		return new FormulareUeberRest($clientService, $this->zugangsdaten(), $this->logger());
	}

	public function testKlonenRuftPostMitFromId(): void {
		$gesehen = null;
		$formulare = $this->restMit(
			['ocs' => ['data' => ['id' => 42, 'hash' => 'editor0000000042', 'title' => 'X - Kopie']]],
			$gesehen,
		);

		$klon = $formulare->formularKlonen(23);

		$this->assertSame(42, $klon->id);
		$this->assertSame('post', $gesehen['methode']);
		$this->assertStringContainsString('/forms?fromId=23', $gesehen['url']);
	}

	public function testAendernSchicktKeyValuePairs(): void {
		$gesehen = null;
		$formulare = $this->restMit(['ocs' => ['data' => []]], $gesehen);

		$formulare->formularAendern(42, ['title' => 'Neuer Titel']);

		$this->assertSame('patch', $gesehen['methode']);
		$this->assertStringContainsString('/forms/42', $gesehen['url']);
		$this->assertSame(['keyValuePairs' => ['title' => 'Neuer Titel']], $gesehen['body']);
	}

	public function testFrageAendernTrifftDieFrageroute(): void {
		$gesehen = null;
		$formulare = $this->restMit(['ocs' => ['data' => []]], $gesehen);

		$formulare->frageAendern(42, 30, ['description' => 'neuer Text']);

		$this->assertStringContainsString('/forms/42/questions/30', $gesehen['url']);
	}

	/**
	 * Der Hash kommt aus der ANTWORT auf die Freigabe, nicht aus dem
	 * Formular. Wer den Editor-Hash nimmt, baut einen Anmeldelink, der ins
	 * Leere fuehrt. Die Hashes hier sind erfunden, ihre Laenge ist
	 * funktionaler Teil des Tests.
	 */
	public function testLinkFreigabeGibtDenHashDerAntwortZurueck(): void {
		$gesehen = null;
		$formulare = $this->restMit(
			['ocs' => ['data' => ['id' => 5, 'shareType' => 3,
				'shareWith' => 'bbbbbbbbbbbbbbbbbbbbbbbb']]],
			$gesehen,
		);

		$hash = $formulare->linkFreigabeAnlegen(42);

		$this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbb', $hash);
		$this->assertStringContainsString('/forms/42/shares', $gesehen['url']);
		$this->assertSame(3, $gesehen['body']['shareType']);
		$this->assertSame(['submit'], $gesehen['body']['permissions']);
	}

	public function testLinkFreigabeOhneHashScheitert(): void {
		$formulare = $this->restMit(
			['ocs' => ['data' => ['id' => 5, 'shareType' => 3, 'shareWith' => '']]],
		);

		$this->expectException(FormulareNichtErreichbar::class);
		$formulare->linkFreigabeAnlegen(42);
	}

	public function testGruppenFreigabeTraegtDreiRechte(): void {
		$gesehen = null;
		$formulare = $this->restMit(['ocs' => ['data' => []]], $gesehen);

		$formulare->gruppenFreigabeAnlegen(42, 'Radfahrschule');

		$this->assertSame(1, $gesehen['body']['shareType']);
		$this->assertSame('Radfahrschule', $gesehen['body']['shareWith']);
		$this->assertSame(
			['submit', 'results', 'results_delete'],
			$gesehen['body']['permissions'],
		);
	}

	public function testLoeschenRuftDelete(): void {
		$gesehen = null;
		$formulare = $this->restMit(['ocs' => ['data' => []]], $gesehen);

		$formulare->formularLoeschen(42);

		$this->assertSame('delete', $gesehen['methode']);
		$this->assertStringContainsString('/forms/42', $gesehen['url']);
	}

	public function testDasEinzelneFormularBringtFragenUndFreigabenMit(): void {
		$formulare = $this->restMit(['ocs' => ['data' => [
			'id' => 19,
			'title' => 'Radfahrschule Musterstadt — Anfängerkurs 12./13.09.2026',
			'questions' => [['id' => 30, 'name' => 'teilnahmebedingungen',
				'text' => 'B', 'description' => '- **Termin:** x']],
			'shares' => [['id' => 1, 'shareType' => 3, 'shareWith' => 'bbbbbbbbbbbbbbbbbbbbbbbb']],
		]]]);

		$formular = $formulare->formularHolen(19);

		$this->assertNotNull($formular->frageMitNamen('teilnahmebedingungen'));
		$this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbb', $formular->oeffentlicherHash());
	}
}
