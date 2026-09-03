/**
 * Setzt einen Knopf neben das Passwortfeld der Einstellungsseite, der das
 * Eingetippte sichtbar macht.
 *
 * Er schaltet nur zwischen type="password" und type="text" um. Damit laesst
 * sich pruefen, ob man sich vertippt hat. Das gespeicherte Passwort steht
 * nie im Feld - die App gibt es nie an den Browser zurueck.
 *
 * Ohne JavaScript entsteht der Knopf gar nicht. Das Feld bleibt dann ein
 * gewoehnliches Passwortfeld, und die Seite funktioniert unveraendert.
 */
(function () {
	'use strict';

	function beschrifte(knopf, wirdGezeigt) {
		knopf.textContent = wirdGezeigt ? 'Verbergen' : 'Zeigen';
		knopf.title = wirdGezeigt ? 'Passwort wieder verbergen' : 'Passwort im Klartext zeigen';
	}

	function baueKnopf(passwortfeld) {
		var knopf = document.createElement('button');
		knopf.type = 'button';
		knopf.className = 'rf-auge';
		beschrifte(knopf, false);

		knopf.addEventListener('click', function () {
			var wirdGezeigt = passwortfeld.type === 'text';
			passwortfeld.type = wirdGezeigt ? 'password' : 'text';
			beschrifte(knopf, !wirdGezeigt);
		});

		return knopf;
	}

	document.addEventListener('DOMContentLoaded', function () {
		var passwortfeld = document.getElementById('radfahrschule-passwort');
		if (passwortfeld === null) {
			return;
		}

		passwortfeld.after(baueKnopf(passwortfeld));
	});
}());
