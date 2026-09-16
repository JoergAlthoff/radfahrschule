<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Termin verschieben</h2>

		<p><strong><?php p($_['kennung']); ?></strong> — <?php p($_['kurstag']); ?></p>

		<p class="rf-hinweis">
			Die Anmeldungen bleiben erhalten, und die öffentlichen Links
			bleiben gültig. Der nächste Schritt zeigt alt und neu
			nebeneinander; geschrieben wird noch nichts.
		</p>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.verschieben.vorschau')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="kennung" value="<?php p($_['kennung']); ?>">
			<?php if ($_['mitgebracht'] !== null) { ?>
				<input type="hidden" name="betreff" value="<?php p($_['mitgebracht']['betreff']); ?>">
				<input type="hidden" name="textAngemeldete" value="<?php p($_['mitgebracht']['textAngemeldete']); ?>">
				<input type="hidden" name="textWartende" value="<?php p($_['mitgebracht']['textWartende']); ?>">
			<?php } ?>

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

			<span class="rf-knopfzeile">
				<button type="submit" class="primary">Weiter zur Kontrolle</button>
				<a class="button"
				   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Abbrechen</a>
			</span>
		</form>
	</div>
</div>
