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
 * Gibt einem Kurs einen neuen Termin.
 *
 * Der Dienst AENDERT die bestehenden Formulare, er legt keine neuen an.
 * Loeschen und neu anlegen schiede aus: Verschoben wird gerade dann, wenn
 * schon Anmeldungen da sind - die waeren sonst weg.
 *
 * Die oeffentlichen Links bleiben gueltig. Sie haengen am Share-Hash des
 * Formulars, nicht am Titel; wer den Anmeldelink schon hat, kommt weiter ans
 * selbe Formular.
 *
 * Gesucht wird ueber die Kennung, nicht ueber ids - dasselbe Muster wie beim
 * Loeschen: Gemischte ids aus zwei Kursen sind damit unmoeglich, und eine
 * Vorlage ist nie treffbar.
 */
final readonly class Kursverschieben {
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
	 * @throws KursNichtGefunden
	 * @throws InvalidArgumentException wenn die Angaben nicht passen
	 * @throws KursGibtEsSchon
	 * @throws GeradeBeschaeftigt
	 * @throws VerschiebenFehlgeschlagen
	 */
	public function verschiebe(
		string $kennung,
		Verschiebung $verschiebung,
		string $benutzer,
		DateTimeImmutable $jetzt,
	): Verschiebeplan {
		// DIESELBE Sperre wie beim Anlegen: Beide vergeben einen Titel.
		$this->sperre->nimm();
		try {
			$alleKurse = Kursliste::ausFormularen($this->formulare->alleEigenen(), $this->titelmuster);

			$kurs = $this->kursMitKennung($alleKurse, $kennung);
			$plan = Verschiebeplan::rechne($kurs, $verschiebung, $jetzt, $this->titelmuster, $this->ablauf);

			// Auf einen vergebenen Termin wird nicht verschoben - sonst
			// traegt der Bestand zwei Kurse mit identischer Kennung, und die
			// Uebersicht kann sie nicht mehr trennen.
			foreach ($alleKurse as $vorhandener) {
				if ($vorhandener->kennung === $plan->neueKennung) {
					throw new KursGibtEsSchon($plan->neueKennung);
				}
			}

			$kette = new Verschiebekette($this->formulare, $plan, $kurs, $this->titelmuster);

			// Erst alles lesen. Faellt hier etwas auf, wurde noch nichts
			// geschrieben - dann gibt es nichts zurueckzuschreiben und
			// nichts zu protokollieren.
			$kette->leseAllesEin();

			try {
				$kette->schreibeAlles();
			} catch (VerschiebenFehlgeschlagen $fehler) {
				throw $this->schreibeZurueck($kette, $plan, $benutzer, $jetzt, $fehler);
			}

			$this->protokoll->schreibe(Vorgang::verschoben(
				jetzt: $jetzt,
				benutzer: $benutzer,
				alteKennung: $plan->alteKennung,
				neueKennung: $plan->neueKennung,
				alterKurstag: $kurs->kurstagIso(),
				neuerKurstag: $plan->bisIso(),
				anmeldungen: $kurs->anmeldungen(),
			));

			return $plan;
		} finally {
			$this->sperre->gib();
		}
	}

	/**
	 * @param list<Kurs> $kurse
	 * @throws KursNichtGefunden
	 */
	private function kursMitKennung(array $kurse, string $kennung): Kurs {
		foreach ($kurse as $kurs) {
			if ($kurs->kennung === $kennung) {
				return $kurs;
			}
		}
		throw new KursNichtGefunden($kennung);
	}

	/**
	 * Stellt die alten Werte wieder her und baut daraus die Meldung.
	 *
	 * Zurueckgegeben wird immer ein Fehler - der Lauf ist gescheitert. Der
	 * Unterschied liegt darin, ob jemand danach etwas tun muss.
	 *
	 * Weitergereicht wird die REINE Ursache, nicht getMessage(): Sonst
	 * stuende das Praefix "Schritt N von M" zweimal in derselben Zeile.
	 */
	private function schreibeZurueck(
		Verschiebekette $kette,
		Verschiebeplan $plan,
		string $benutzer,
		DateTimeImmutable $jetzt,
		VerschiebenFehlgeschlagen $ursache,
	): VerschiebenFehlgeschlagen {
		$zurueck = new Zurueckschreiben($this->formulare, $this->zugangsdaten->basisUrl());
		$stehengeblieben = $zurueck->stelleWiederHer($kette->geaenderte());

		if ($stehengeblieben === []) {
			$this->protokoll->schreibe(Vorgang::verschiebungZurueckgerollt(
				jetzt: $jetzt,
				benutzer: $benutzer,
				alteKennung: $plan->alteKennung,
				neueKennung: $plan->neueKennung,
				grund: $ursache->getMessage(),
			));

			// schonGeschrieben ist hier FALSCH: Das Kennzeichen fragt, ob in
			// Nextcloud etwas steht, das jemand aufraeumen muss. Nach einem
			// gelungenen Zurueckschreiben steht der Kurs wieder wie vorher.
			// Auf true machte es die Ueberschrift "Das Verschieben brach ab"
			// - die jemanden nach Ueberresten suchen schickt - und darunter
			// stand widerspruechlich "Der Kurs steht wieder auf dem alten
			// Termin."
			return new VerschiebenFehlgeschlagen(
				$ursache->ursache,
				schonGeschrieben: false,
				schritt: $ursache->schritt,
				schritteInsgesamt: $ursache->schritteInsgesamt,
				zusatz: 'Der Kurs steht wieder auf dem alten Termin. '
					. 'Bitte noch einmal versuchen.',
				previous: $ursache,
			);
		}

		$beschriftungen = array_map(
			static fn (AlterStand $stand): string => $stand->beschriftung, $stehengeblieben);

		$this->protokoll->schreibe(Vorgang::verschiebenAbgebrochen(
			jetzt: $jetzt,
			benutzer: $benutzer,
			alteKennung: $plan->alteKennung,
			neueKennung: $plan->neueKennung,
			stehengeblieben: implode(', ', $beschriftungen),
			grund: $ursache->getMessage(),
		));

		return new VerschiebenFehlgeschlagen(
			$ursache->ursache,
			schonGeschrieben: true,
			schritt: $ursache->schritt,
			schritteInsgesamt: $ursache->schritteInsgesamt,
			zusatz: $zurueck->hinweis($stehengeblieben),
			previous: $ursache,
		);
	}
}
