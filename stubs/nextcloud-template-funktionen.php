<?php

declare(strict_types=1);

/**
 * Die Template-Funktionen von Nextcloud, nur fuer die Werkzeuge.
 *
 * Diese Datei wird nie ausgefuehrt und nie in den Container gespiegelt.
 * PHPStan liest sie ueber scanFiles in phpstan.neon, PhpStorm ueber den
 * Include-Pfad. Zur Laufzeit laedt Nextcloud die echten Funktionen selbst.
 *
 * Warum es sie braucht: p() und print_unescaped() stehen in
 * /var/www/html/lib/private/Template/functions.php - also im PRIVATEN Teil
 * von Nextcloud. Das Paket nextcloud/ocp liefert nur den oeffentlichen
 * Namensraum OCP, und dort sind sie nicht enthalten. Ohne diese Datei meldet
 * jedes Werkzeug jeden Aufruf als unbekannte Funktion - und das ist der
 * groesste Teil aller Meldungen.
 *
 * Die Signaturen sind aus Nextcloud 32 abgeschrieben, nicht geraten.
 *
 * DER FEHLENDE PARAMETER-TYP IST ABSICHT. PhpStorm meldet ihn als Hinweis,
 * und ein "string $string" waere verlockend - aber dann waere der Stub
 * strenger als das Original. Das Template ruft p() mit ZAHLEN auf
 * (p($kurs['anmeldungen'])). Heute faellt das nicht auf, weil $_ nur als
 * "array" deklariert ist und PHPStan die Typen darin nicht kennt. Auf
 * Stufe 6, mit genaueren @var-Angaben, wuerde jeder dieser Aufrufe gemeldet
 * - obwohl er im Betrieb laeuft.
 */

/**
 * Gibt einen Wert aus und maskiert ihn dabei.
 *
 * @param string $string
 */
function p($string): void {
}

/**
 * Gibt einen Wert unmaskiert aus - jeder Aufruf ist eine moegliche XSS-Luecke.
 *
 * @param string $string the string which will be printed as it is
 */
function print_unescaped($string): void {
}
