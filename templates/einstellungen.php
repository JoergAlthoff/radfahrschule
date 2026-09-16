<?php
declare(strict_types=1);
/** @var array $_ */
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
// Setzt nur den Knopf, der das eingetippte Passwort sichtbar macht. Ohne
// ihn bleibt die Seite vollstaendig bedienbar.
\OCP\Util::addScript('radfahrschule', 'einstellungen');
?>
<div class="section rf-einstellungen" id="radfahrschule-einstellungen">
	<h2>Radfahrschule</h2>

	<?php /* Ein gewoehnliches Formular mit action und POST. Nextclouds eigene
	         Einstellungsseiten schicken per JS ab; das braucht diese App
	         nicht. Das einzige Skript setzt den Auge-Knopf am Passwortfeld -
	         faellt es aus, bleibt die Seite vollstaendig bedienbar.

	         Das Fragment landet in einem div innerhalb von main - es steht
	         also in KEINEM fremden Formular. Ein verschachteltes form loeste
	         der Browser stillschweigend auf. */ ?>
	<form method="post"
	      action="<?php p($urls->linkToRoute('radfahrschule.einstellungen.speichere')); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

		<h3>Zugang zur Forms-API</h3>

		<?php /* Der Stand des Zugangs, gepruefte Auskunft und keine
		         Vermutung: Verwaltung hat beim Aufbau dieser Seite einen
		         lesenden Aufruf gemacht. Ohne ihn zeigt sich ein Tippfehler
		         erst an einer leeren Kursliste, auf einer anderen Seite.

		         Ausgesagt wird in Worten und nicht ueber Farbe. */ ?>
		<?php if ($_['zugangsfehler'] !== '') { ?>
			<p class="rf-warnung">
				<strong>Der Zugang trägt nicht.</strong>
				<?php p($_['zugangsfehler']); ?>
			</p>
		<?php } elseif ($_['zugangGeprueft']) { ?>
			<p class="rf-hinweis">
				<strong>Der Zugang steht.</strong>
				Forms antwortet mit diesem Konto.
			</p>
		<?php } ?>

		<p class="settings-hint">
			Das Passwort ist ein App-Passwort des Dienstkontos, kein
			Anmeldepasswort.
		</p>
		<p>
			<label for="radfahrschule-basis-url">Adresse der Instanz</label><br>
			<input type="url" id="radfahrschule-basis-url" name="basisUrl"
			       value="<?php p($_['basisUrl']); ?>" required>
		</p>
		<p>
			<label for="radfahrschule-benutzer">Dienstkonto</label><br>
			<input type="text" id="radfahrschule-benutzer" name="benutzer"
			       value="<?php p($_['benutzer']); ?>" required>
		</p>

		<?php /* Die Anleitung steht hier und nicht in einer Doku: Ohne sie
		         sieht das Feld aus wie ein frei waehlbares Passwort. Ein
		         ausgedachter Wert wird brav gespeichert und scheitert dann
		         bei jedem Aufruf mit HTTP 401.

		         Ein Link auf /settings/user/security waere eine Falle - das
		         ist immer die Seite des ANGEMELDETEN Kontos. Ein Admin
		         erzeugte dort ein Passwort fuer sich selbst. */ ?>
		<p class="settings-hint">
			Dieses Passwort lässt sich nicht frei wählen — Nextcloud stellt es
			aus. So kommt man daran:
		</p>
		<ol class="rf-anleitung">
			<li>Bei Nextcloud als <strong><?php p($_['benutzer'] !== '' ? $_['benutzer'] : 'Dienstkonto'); ?></strong> anmelden.</li>
			<li>Oben rechts auf das Profilbild, dann <strong>Persönliche Einstellungen</strong>.</li>
			<li>In der linken Spalte <strong>Sicherheit</strong> wählen.</li>
			<li>Ganz unten unter <strong>Geräte &amp; Sitzungen</strong> einen Namen eingeben, etwa „Radfahrschule-App", und auf <strong>Neues App-Passwort erstellen</strong> klicken.</li>
			<li>Nextcloud zeigt eine lange Zeichenkette. Sie erscheint <strong>nur dieses eine Mal</strong>.</li>
			<li>Die Zeichenkette kopieren, hier unten einfügen und speichern.</li>
		</ol>
		<p class="settings-hint">
			Wer das App-Passwort bei diesem Konto widerruft oder ein neues
			erzeugt, muss es hier neu eintragen. Sonst kommt die App nicht
			mehr an die Formulare, und die Kursliste bleibt leer.
		</p>

		<?php /* Der Wert steht nie im Feld - die App gibt ihn nie zurueck.
		         Leer heisst deshalb "nicht aendern"; der Platzhalter sagt,
		         ob ueberhaupt eines hinterlegt ist. */ ?>
		<p>
			<label for="radfahrschule-passwort">App-Passwort</label><br>
			<span class="rf-passwortzeile">
				<input type="password" id="radfahrschule-passwort" name="appPasswort"
				       autocomplete="new-password"
				       placeholder="<?php p($_['passwortGesetzt'] ? '— gesetzt, leer lassen zum Behalten —' : 'noch nicht gesetzt'); ?>">
			</span>
		</p>

		<p class="settings-hint">
			Wer in dieser Gruppe steht, darf die App öffnen und Kurse anlegen,
			verschieben und löschen — und bekommt jedes neue Formular
			freigegeben. Administratoren dürfen dasselbe.
		</p>
		<p>
			<label for="radfahrschule-gruppe">Gruppe</label><br>
			<input type="text" id="radfahrschule-gruppe" name="freigabeGruppe"
			       value="<?php p($_['freigabeGruppe']); ?>" required>
		</p>

		<h3>Angaben des Vereins</h3>
		<?php /* Sie stehen nicht im Code, weil jede Instanz einen anderen
		         Betreiber hat. Ohne Name und Kursart legt die App nichts an
		         und sagt das - eine Vorbelegung stuende wieder im Code. */ ?>
		<p class="settings-hint">
			Der Name steht am Anfang jedes Formulartitels, die Kursart
			dahinter. Ein Titel sieht damit so aus:
			<strong><?php p($_['name'] !== '' ? $_['name'] : 'Radfahrschule Musterstadt'); ?>
			— Anmeldung <?php p($_['kursart'] !== '' ? $_['kursart'] : 'Anfängerkurs'); ?>
			12.09.2029</strong>.
		</p>
		<?php /* Der Trenner ist keine Eingabe. Wer ihn selbst tippen
		         muesste, braeuchte einen Gedankenstrich - und den findet
		         man auf keiner Tastatur schnell. */ ?>
		<p>
			<label for="radfahrschule-name">Name des Vereins</label><br>
			<input type="text" id="radfahrschule-name" name="name"
			       value="<?php p($_['name']); ?>"
			       placeholder="Radfahrschule Musterstadt" required>
		</p>
		<p>
			<label for="radfahrschule-kursart">Kursart</label><br>
			<input type="text" id="radfahrschule-kursart" name="kursart"
			       value="<?php p($_['kursart']); ?>"
			       placeholder="Anfängerkurs" required>
		</p>

		<?php /* Aendert man den Namen, findet die App die vorhandenen Kurse
		         nicht mehr: Der Praefix ist die Klammer zwischen Anmeldung
		         und Warteliste. */ ?>
		<p class="settings-hint">
			Ist bereits ein Kurs angelegt, darf der Name nicht mehr geändert
			werden — sonst findet die App die vorhandenen Formulare nicht
			mehr.
		</p>

		<p class="settings-hint">
			Nach wie vielen Tagen die Anmeldedaten gelöscht sein müssen. Die
			Zahl steht im Formular und bindet den Verein. Die Übersicht nennt
			daraus für jeden Kurs den Tag.
		</p>
		<p>
			<label for="radfahrschule-aufbewahrung">Aufbewahrung in Tagen</label><br>
			<input type="number" id="radfahrschule-aufbewahrung" name="aufbewahrungTage"
			       value="<?php p((string)$_['aufbewahrungTage']); ?>"
			       min="1" max="3650" required>
		</p>

		<?php /* Eine Auswahlliste und kein Feld: Ein Tippfehler fiele still
		         auf Europe/Berlin zurueck, und die Kurstage laegen um einen
		         Tag daneben, ohne dass etwas meldet. */ ?>
		<p class="settings-hint">
			In dieser Zeitzone finden die Kurse statt. Sie entscheidet, welcher
			Kalendertag „heute" ist und wann ein Anmeldeschluss abläuft — nicht
			die Einstellung des Servers.
		</p>
		<p>
			<label for="radfahrschule-zeitzone">Zeitzone</label><br>
			<select id="radfahrschule-zeitzone" name="zeitzone">
				<?php foreach ($_['zeitzonen'] as $zeitzone) { ?>
					<option value="<?php p($zeitzone); ?>"<?php p($zeitzone === $_['zeitzone'] ? ' selected' : ''); ?>><?php p($zeitzone); ?></option>
				<?php } ?>
			</select>
		</p>

		<?php /* Freiwillig. Bleibt das Feld leer, entfaellt der Absatz auf
		         den Seiten nach dem Anlegen und Verschieben. */ ?>
		<p class="settings-hint">
			Ein freiwilliger Satz, der nach dem Anlegen und Verschieben eines
			Kurses erscheint. Gedacht für den Hinweis, dass der Termin auch
			anderswo eingetragen werden muss — etwa in einem Terminportal.
		</p>
		<p>
			<label for="radfahrschule-terminportal">Hinweis nach dem Anlegen</label><br>
			<input type="text" id="radfahrschule-terminportal" name="terminportalHinweis"
			       value="<?php p($_['terminportalHinweis']); ?>"
			       placeholder="Der Termin gehört noch ins Terminportal.">
		</p>

		<h3>Benachrichtigungen</h3>
		<?php /* Nextcloud verschickt unter seiner eigenen Absenderadresse.
		         Bei einer gehosteten Instanz gehoert sie dem Hoster, und
		         Antworten liefen dort ins Leere. */ ?>
		<?php if ($_['antwortadresseUngueltig']) { ?>
			<p class="rf-warnung">
				<strong>Die Antwortadresse ist keine gültige Mailadresse.</strong>
				Nachrichten gehen trotzdem hinaus, aber ohne sie. Antworten
				landen dann bei der Absenderadresse dieser Nextcloud.
			</p>
		<?php } ?>
		<p class="settings-hint">
			Nachrichten an Teilnehmer verschickt Nextcloud unter seiner eigenen
			Absenderadresse. Wer darauf antwortet, schreibt an diese Adresse.
		</p>
		<p>
			<label for="radfahrschule-antwortadresse">Antwortadresse</label><br>
			<input type="email" id="radfahrschule-antwortadresse" name="antwortadresse"
			       value="<?php p($_['antwortadresse']); ?>"
			       placeholder="kurse@example.org">
		</p>

		<p class="settings-hint">
			Beim Löschen eines Kurses lässt sich eine Absage an die Eingetragenen
			schicken. Diese Texte stehen dort vorbelegt und lassen sich vor dem
			Löschen noch ändern. Ersetzt werden {anrede}, {vorname}, {nachname},
			{kursart} und {termin}. Ein leerer Text heißt: Diese Liste bekommt
			keine Absage.
		</p>
		<p>
			<label for="radfahrschule-absage-betreff">Absage: Betreff</label><br>
			<input type="text" id="radfahrschule-absage-betreff" name="absageBetreff"
			       value="<?php p($_['absageBetreff']); ?>"
			       placeholder="Der {kursart} am {termin} fällt aus">
		</p>
		<p>
			<label for="radfahrschule-absage-angemeldete">Absage: Text an die Angemeldeten</label><br>
			<textarea id="radfahrschule-absage-angemeldete" name="absageTextAngemeldete"
			          rows="6"><?php p($_['absageTextAngemeldete']); ?></textarea>
		</p>
		<p>
			<label for="radfahrschule-absage-wartende">Absage: Text an die Warteliste</label><br>
			<textarea id="radfahrschule-absage-wartende" name="absageTextWartende"
			          rows="6"><?php p($_['absageTextWartende']); ?></textarea>
		</p>

		<p class="settings-hint">
			Beim Verschieben eines Kurses lässt sich eine Nachricht an die
			Eingetragenen schicken. Diese Texte stehen dort vorbelegt und lassen
			sich vorher noch ändern. Ersetzt werden {anrede}, {vorname},
			{nachname}, {kursart}, {termin} für den bisherigen und {neuer_termin}
			für den neuen Termin. Ein leerer Text heißt: Diese Liste bekommt
			keine Nachricht.
		</p>
		<p>
			<label for="radfahrschule-verschiebung-betreff">Verschiebung: Betreff</label><br>
			<input type="text" id="radfahrschule-verschiebung-betreff" name="verschiebungBetreff"
			       value="<?php p($_['verschiebungBetreff']); ?>"
			       placeholder="Der {kursart} am {termin} ist verschoben">
		</p>
		<p>
			<label for="radfahrschule-verschiebung-angemeldete">Verschiebung: Text an die Angemeldeten</label><br>
			<textarea id="radfahrschule-verschiebung-angemeldete" name="verschiebungTextAngemeldete"
			          rows="6"><?php p($_['verschiebungTextAngemeldete']); ?></textarea>
		</p>
		<p>
			<label for="radfahrschule-verschiebung-wartende">Verschiebung: Text an die Warteliste</label><br>
			<textarea id="radfahrschule-verschiebung-wartende" name="verschiebungTextWartende"
			          rows="6"><?php p($_['verschiebungTextWartende']); ?></textarea>
		</p>

		<p>
			<button type="submit" class="primary">Speichern</button>
		</p>
	</form>
</div>
