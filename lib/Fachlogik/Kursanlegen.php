<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Fachlogik;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Radfahrschule\Einstellungen\Zugangsdaten;
use OCA\Radfahrschule\Formulare\Formulare;
use OCA\Radfahrschule\Protokoll\Protokoll;
use OCA\Radfahrschule\Protokoll\Vorgang;
use OCA\Radfahrschule\Sperre\Schreibsperre;

/**
 * Legt einen Kurs an: Pruefung, Sperre, Schreibkette, Protokoll.
 *
 * Ein Anlegen endet auf zwei Arten, nicht auf drei. Entweder der Kurs steht,
 * oder es ist nichts entstanden - dafuer sorgt das Zurueckrollen. Nur wenn
 * das Aufraeumen selbst misslingt, bleibt etwas stehen, und nur dann
 * verlangt der Vorgang, dass jemand etwas tut.
 */
final readonly class Kursanlegen {
	public function __construct(
		private Formulare $formulare,
		private Protokoll $protokoll,
		private Schreibsperre $sperre,
		private Zugangsdaten $zugangsdaten,
		private Titelmuster $titelmuster,
		private Ablauf $ablauf,
	) {
	}

	/**
	 * benutzer steht nur im Protokoll. Er wird hier nicht geprueft - wer
	 * hierher kommt, ist angemeldet, und das Recht hat der Controller
	 * geprueft.
	 *
	 * @throws InvalidArgumentException wenn die Angaben nicht passen
	 * @throws KursGibtEsSchon
	 * @throws GeradeBeschaeftigt
	 * @throws AnlegenFehlgeschlagen
	 */
	public function legeAn(
		Eingabe $eingabe,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Ergebnis {
		// Ausserhalb der Sperre: Sie rechnet nur und fasst Nextcloud nicht
		// an. Wer sich vertippt, soll auch dann sofort eine Meldung sehen,
		// wenn gerade jemand anlegt.
		$vorschau = Vorschau::rechne($eingabe, $jetzt, $this->titelmuster, $this->ablauf);

		$this->sperre->nimm();
		try {
			// Innerhalb der Sperre, und nur so wirksam: Eine Pruefung, die
			// endet, bevor der Titel steht, kann von einer zweiten Anfrage
			// unterlaufen werden.
			$this->pruefeKennungIstFrei($vorschau);

			$kette = new Schreibkette(
				$this->formulare,
				$vorschau,
				$this->zugangsdaten->freigabeGruppe(),
			);

			try {
				$ergebnis = $kette->fuehreAus();
			} catch (AnlegenFehlgeschlagen $fehler) {
				throw $this->rolleZurueck($kette, $vorschau, $benutzer, $jetzt, $fehler);
			}

			$this->protokoll->schreibe(Vorgang::angelegt(
				jetzt: $jetzt,
				benutzer: $benutzer,
				kennung: $vorschau->kennung,
				kurstag: $vorschau->bisIso(),
				anmeldungId: $ergebnis->anmeldungId,
				wartelisteId: $ergebnis->wartelisteId,
				plaetze: $vorschau->plaetze,
			));

			return $ergebnis;
		} finally {
			$this->sperre->gib();
		}
	}

	/**
	 * Weist ab, wenn es den Kurs schon gibt.
	 *
	 * Ohne sie erzeugt ein Doppelklick zwei Kurspaare mit identischen
	 * Titeln. Sie steht VOR dem ersten Klon, und das ist ihr ganzer Sinn:
	 * Eine Meldung danach waere wertlos, weil dann schon ein halber Kurs in
	 * Nextcloud staende.
	 *
	 * Der Aufruf zaehlt NICHT als Schritt: Die Schrittnummern in den
	 * Fehlermeldungen zaehlen die Schreibkette, und hier wird nur gelesen.
	 *
	 * @throws KursGibtEsSchon
	 */
	private function pruefeKennungIstFrei(Vorschau $vorschau): void {
		$formulare = $this->formulare->alleEigenen();
		$gesucht = $vorschau->kennung;

		foreach (Kursliste::ausFormularen($formulare, $this->titelmuster) as $kurs) {
			if ($kurs->kennung === $gesucht) {
				throw new KursGibtEsSchon($gesucht);
			}
		}
	}

	/**
	 * Loescht, was der Lauf angelegt hat, und baut daraus die Meldung.
	 *
	 * Zurueckgegeben wird immer ein Fehler - der Lauf ist gescheitert. Der
	 * Unterschied liegt darin, ob jemand danach etwas tun muss.
	 *
	 * Die Ausnahme wird neu gebaut statt ergaenzt: Ihre Meldung ist in PHP
	 * nicht nachtraeglich aenderbar. Weitergereicht wird die REINE Ursache,
	 * nicht getMessage() - sonst stuende das Praefix "Schritt N von M"
	 * zweimal in derselben Zeile.
	 */
	private function rolleZurueck(
		Schreibkette $kette,
		Vorschau $vorschau,
		string $benutzer,
		DateTimeImmutable $jetzt,
		AnlegenFehlgeschlagen $ursache,
	): AnlegenFehlgeschlagen {
		$zurueck = new Zurueckrollen($this->formulare, $this->zugangsdaten->basisUrl());
		$stehengeblieben = $zurueck->raeumeAuf($kette->erzeugte());

		if ($stehengeblieben === [] && !$kette->klonIstUngewiss()) {
			$this->protokoll->schreibe(Vorgang::zurueckgerollt(
				jetzt: $jetzt,
				benutzer: $benutzer,
				kennung: $vorschau->kennung,
				kurstag: $vorschau->bisIso(),
				grund: $ursache->getMessage(),
			));

			return new AnlegenFehlgeschlagen(
				$ursache->schritt, $ursache->schritteInsgesamt, $ursache->ursache,
				$ursache, 'Es ist nichts entstanden. Bitte noch einmal versuchen.');
		}

		// Ein Klon-Aufruf ging hinaus, und seine Antwort blieb aus: Die id
		// kennt der Aufrufer erst AUS der Antwort, also gibt es nichts
		// wegzuraeumen und nichts zu benennen. Zu sagen, es sei nichts
		// entstanden, waere hier eine Behauptung ins Blaue - und niemand
		// wuerde je nach der Kopie sehen, die Kursliste als Vorlage
		// aussortiert.
		if ($stehengeblieben === []) {
			$this->protokoll->schreibe(Vorgang::anlegenAbgebrochen(
				jetzt: $jetzt,
				benutzer: $benutzer,
				kennung: $vorschau->kennung,
				kurstag: $vorschau->bisIso(),
				anmeldungId: null,
				wartelisteId: null,
				grund: $ursache->getMessage(),
			));

			return new AnlegenFehlgeschlagen(
				$ursache->schritt, $ursache->schritteInsgesamt, $ursache->ursache,
				$ursache,
				'Ob dabei schon ein Formular in Nextcloud entstanden ist, lässt '
				. 'sich nicht sagen — die Antwort blieb aus. Bitte in Nextcloud '
				. 'unter Formulare nachsehen: Eine Kopie heißt „VORLAGE … - '
				. 'Kopie“ und gehört gelöscht.');
		}

		$this->protokoll->schreibe(Vorgang::anlegenAbgebrochen(
			jetzt: $jetzt,
			benutzer: $benutzer,
			kennung: $vorschau->kennung,
			kurstag: $vorschau->bisIso(),
			anmeldungId: self::idMit($stehengeblieben, 'Anmeldung'),
			wartelisteId: self::idMit($stehengeblieben, 'Warteliste'),
			grund: $ursache->getMessage(),
		));

		return new AnlegenFehlgeschlagen(
			$ursache->schritt, $ursache->schritteInsgesamt, $ursache->ursache,
			$ursache, $zurueck->hinweis($stehengeblieben));
	}

	/**
	 * @param list<ErzeugtesFormular> $stehengeblieben
	 */
	private static function idMit(array $stehengeblieben, string $beschriftung): ?int {
		foreach ($stehengeblieben as $formular) {
			if ($formular->beschriftung === $beschriftung) {
				return $formular->id;
			}
		}
		return null;
	}
}
