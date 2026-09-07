<?php

declare(strict_types=1);

namespace OCA\Radfahrschule\Formulare;

/**
 * Die Naht zu Nextcloud Forms.
 *
 * Forms hat keine oeffentliche Schnittstelle fuer andere Apps: Die
 * Service-Klassen sind Interna ohne Zusage. Deshalb spricht die einzige
 * Implementierung die REST-API. Kommt spaeter eine, die Forms direkt
 * aufruft, wird sie hierneben gestellt - die Fachlogik merkt nichts davon.
 *
 * Die Methodennamen sind die des Go-Vorgaengers, nur kleingeschrieben. Wer
 * zwischen beiden Repos springt, soll nicht uebersetzen muessen.
 */
interface Formulare {
	/**
	 * Alle Formulare, die dem Dienstkonto gehoeren.
	 *
	 * Die Liste liefert WENIGER als das einzelne Formular: description,
	 * questions und shares fehlen darin.
	 *
	 * @return list<Formular>
	 * @throws FormulareNichtErreichbar
	 */
	public function alleEigenen(): array;

	/**
	 * Ein Formular mit allen Fragen und Freigaben.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function formularHolen(int $id): Formular;

	/**
	 * Legt eine Kopie an. Abgegebene Antworten kopiert Nextcloud nicht mit.
	 *
	 * Was der Klon NICHT uebernimmt: expires und showExpiration. Was er
	 * stillschweigend uebernimmt: maxSubmissions. Beides muss der Aufrufer
	 * nachziehen.
	 *
	 * Den Titel vergibt Nextcloud: der der Vorlage, mit einem Zusatz
	 * dahinter. Der Zusatz ist UEBERSETZT - auf Deutsch " - Kopie", auf
	 * Englisch " - Copy". Der Aufruf nimmt kein Titelfeld an, es gibt also
	 * keinen Weg daran vorbei; der Aufrufer benennt sofort danach um. Auf
	 * diesen Zusatz darf sich kein Code verlassen.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function formularKlonen(int $vorlageId): Formular;

	/**
	 * Setzt einzelne Felder eines Formulars.
	 *
	 * Achtung: keyValuePairs prueft keine Schluesselnamen. Ein Tippfehler
	 * geht als 200 durch und aendert nichts. Nach dem Schreiben
	 * zuruecklesen.
	 *
	 * @param array<string, mixed> $felder
	 * @throws FormulareNichtErreichbar
	 */
	public function formularAendern(int $id, array $felder): void;

	/**
	 * Setzt einzelne Felder einer Frage. Derselbe Koerper wie beim Formular.
	 *
	 * @param array<string, mixed> $felder
	 * @throws FormulareNichtErreichbar
	 */
	public function frageAendern(int $formularId, int $frageId, array $felder): void;

	/**
	 * Entfernt ein Formular samt seiner Antworten.
	 *
	 * Es gibt keinen Papierkorb. Der Aufrufer muss sicher sein, was er
	 * loescht.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function formularLoeschen(int $id): void;

	/**
	 * Gibt ein Formular oeffentlich per Link frei und liefert den Hash.
	 *
	 * Dieser Hash ist NICHT der aus Formular::$hash.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function linkFreigabeAnlegen(int $formularId): string;

	/**
	 * Teilt ein Formular mit einer Nextcloud-Gruppe.
	 *
	 * Die Rechte sind submit, results und results_delete - mitarbeiten,
	 * Anmeldungen sehen und einzelne davon loeschen. Das letzte braucht der
	 * Alltag: Eine Absage wird bearbeitet, indem die Anmeldung geloescht
	 * wird, sonst wird der Platz nicht frei.
	 *
	 * @throws FormulareNichtErreichbar
	 */
	public function gruppenFreigabeAnlegen(int $formularId, string $gruppe): void;
}
