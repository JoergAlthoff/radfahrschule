<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Tests\Fachlogik;

use OCA\Radfahrschule\Fachlogik\ErzeugtesFormular;
use OCA\Radfahrschule\Fachlogik\Zurueckrollen;
use OCA\Radfahrschule\Formulare\Formular;
use OCA\Radfahrschule\Tests\Formulare\FormulareDoppel;
use PHPUnit\Framework\TestCase;

final class ZurueckrollenTest extends TestCase {
	private const BASIS = 'https://cloud.example.org';

	/**
	 * Die Editor-Hashes sind erfunden und 16 Zeichen lang - so lang sind sie
	 * in Nextcloud. Die Laenge trennt sie vom Share-Hash mit 24.
	 */
	private const HASH_WARTELISTE = 'editor0000000100';
	private const HASH_ANMELDUNG = 'editor0000000101';

	private function doppelMit(int ...$ids): FormulareDoppel {
		$bestand = [];
		foreach ($ids as $id) {
			$bestand[] = new Formular(
				id: $id, hash: 'editor' . str_pad((string)$id, 10, '0', STR_PAD_LEFT),
				titel: 'Klon ' . $id, beschreibung: '', abgaben: 0, ablauf: 0,
			);
		}
		return new FormulareDoppel($bestand);
	}

	/** @return list<ErzeugtesFormular> */
	private function erzeugte(): array {
		return [
			new ErzeugtesFormular('Warteliste', 100, self::HASH_WARTELISTE),
			new ErzeugtesFormular('Anmeldung', 101, self::HASH_ANMELDUNG),
		];
	}

	public function testEsBleibtNichtsStehen(): void {
		$doppel = $this->doppelMit(100, 101);

		$stehengeblieben = (new Zurueckrollen($doppel, self::BASIS))
			->raeumeAuf($this->erzeugte());

		$this->assertSame([], $stehengeblieben);
		$this->assertSame([], $doppel->bestand());
	}

	/**
	 * Geloescht wird rueckwaerts, also das zuletzt Angelegte zuerst. Das ist
	 * keine technische Notwendigkeit, sondern haelt die Ordnung der Meldung:
	 * Was zuletzt entstand, steht dem Fehler am naechsten.
	 */
	public function testGeloeschtWirdRueckwaerts(): void {
		$doppel = $this->doppelMit(100, 101);

		(new Zurueckrollen($doppel, self::BASIS))->raeumeAuf($this->erzeugte());

		$this->assertSame(
			['formularLoeschen:101', 'formularLoeschen:100'],
			array_values(array_filter(
				$doppel->aufrufe,
				static fn (string $aufruf): bool => str_starts_with($aufruf, 'formularLoeschen'),
			)),
		);
	}

	public function testWasSichNichtLoeschenLaesstWirdGemeldet(): void {
		$doppel = $this->doppelMit(100, 101);
		$doppel->laessLoeschenScheitern(101);

		$stehengeblieben = (new Zurueckrollen($doppel, self::BASIS))
			->raeumeAuf($this->erzeugte());

		$this->assertCount(1, $stehengeblieben);
		$this->assertSame('Anmeldung', $stehengeblieben[0]->beschriftung);
	}

	/**
	 * Ein Fehlschlag beim ersten Loeschen darf das zweite nicht verhindern.
	 * Sonst bliebe mehr stehen als noetig.
	 */
	public function testEinFehlschlagStopptDasAufraeumenNicht(): void {
		$doppel = $this->doppelMit(100, 101);
		// 101 wird zuerst versucht - rueckwaerts - und scheitert.
		$doppel->laessLoeschenScheitern(101);

		(new Zurueckrollen($doppel, self::BASIS))->raeumeAuf($this->erzeugte());

		// 100 ist trotzdem weg. Ohne das bliebe mehr stehen als noetig.
		$uebrig = array_map(
			static fn (Formular $formular): int => $formular->id, $doppel->bestand());
		$this->assertSame([101], $uebrig);
	}

	/**
	 * Eine Formular-ID ist in Nextcloud NIRGENDS zu sehen: Die Liste zeigt
	 * Titel, die Editor-Adresse zeigt einen Hash.
	 */
	public function testDerHinweisNenntEinenLinkUndKeineNummer(): void {
		$hinweis = (new Zurueckrollen($this->doppelMit(), self::BASIS))
			->hinweis([new ErzeugtesFormular('Anmeldung', 101, self::HASH_ANMELDUNG)]);

		$this->assertStringContainsString(
			'https://cloud.example.org/apps/forms/' . self::HASH_ANMELDUNG . '/edit',
			$hinweis,
		);
		$this->assertStringContainsString('Anmeldung', $hinweis);
		// Die nackte Nummer taucht nirgends auf - sie waere fuer den
		// Menschen davor wertlos.
		$this->assertStringNotContainsString('id=', $hinweis);
	}
}
