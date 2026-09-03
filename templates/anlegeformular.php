<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Neuen Kurs anlegen</h2>

		<p class="rf-hinweis">
			Es entstehen zwei Formulare: die Anmeldung und die Warteliste.
			Der nächste Schritt zeigt sie zur Kontrolle, geschrieben wird
			noch nichts.
		</p>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.anlegen.vorschau')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">

			<span class="rf-feld">
				<label for="rf-vorlage-anmeldung">Vorlage der Anmeldung</label>
				<select id="rf-vorlage-anmeldung" name="vorlage_anmeldung" required>
					<?php if ($_['freieWahlAnmeldung']) { ?>
						<option value="">— bitte wählen —</option>
					<?php } ?>
					<?php foreach ($_['vorlagenAnmeldung'] as $vorlage) { ?>
						<option value="<?php p($vorlage['id']); ?>"
							<?php if ($vorlage['gewaehlt']) { print_unescaped('selected'); } ?>>
							<?php p($vorlage['titel']); ?>
						</option>
					<?php } ?>
				</select>
			</span>

			<span class="rf-feld">
				<label for="rf-vorlage-warteliste">Vorlage der Warteliste</label>
				<select id="rf-vorlage-warteliste" name="vorlage_warteliste" required>
					<?php if ($_['freieWahlWarteliste']) { ?>
						<option value="">— bitte wählen —</option>
					<?php } ?>
					<?php foreach ($_['vorlagenWarteliste'] as $vorlage) { ?>
						<option value="<?php p($vorlage['id']); ?>"
							<?php if ($vorlage['gewaehlt']) { print_unescaped('selected'); } ?>>
							<?php p($vorlage['titel']); ?>
						</option>
					<?php } ?>
				</select>
			</span>

			<span class="rf-feld">
				<label for="rf-von">Erster Kurstag</label>
				<input id="rf-von" type="date" name="von"
				       value="<?php p($_['vonIso']); ?>" required>
			</span>

			<span class="rf-feld">
				<label for="rf-bis">Letzter Kurstag</label>
				<input id="rf-bis" type="date" name="bis"
				       value="<?php p($_['bisIso']); ?>" required>
			</span>

			<span class="rf-feld">
				<label for="rf-anmeldeschluss">Anmeldeschluss</label>
				<input id="rf-anmeldeschluss" type="date" name="anmeldeschluss"
				       value="<?php p($_['anmeldeschlussIso']); ?>" required>
			</span>

			<span class="rf-feld">
				<label for="rf-plaetze">Plätze</label>
				<input id="rf-plaetze" type="number" name="plaetze" min="1"
				       value="<?php p($_['plaetze']); ?>" required>
			</span>

			<span class="rf-knopfzeile">
				<button type="submit" class="primary">Weiter zur Kontrolle</button>
				<a class="button"
				   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Abbrechen</a>
			</span>
		</form>
	</div>
</div>
