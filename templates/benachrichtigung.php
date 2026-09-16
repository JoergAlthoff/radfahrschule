<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Nachricht an die Teilnehmer</h2>

		<p class="rf-hinweis"><?php p($_['kennung']); ?> — <?php p($_['kurstag']); ?></p>

		<?php if ($_['fehler'] !== '') { ?>
			<p class="rf-warnung"><?php p($_['fehler']); ?></p>
		<?php } ?>

		<div class="rf-tabellenrahmen">
			<table class="rf-tabelle">
				<thead>
					<tr>
						<th>Liste</th>
						<th class="rf-zahl">Einträge</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($_['zeilen'] as $zeile) { ?>
					<tr>
						<td data-spalte="Liste"><?php p($zeile['beschriftung']); ?></td>
						<td class="rf-zahl" data-spalte="Einträge"><?php p($zeile['eintraege']); ?></td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>

		<p class="rf-hinweis">
			Jeder Eintrag bekommt eine eigene Mail. Niemand sieht die Adresse
			eines anderen. Bleibt ein Text leer, bekommt diese Liste nichts.
		</p>
		<p class="rf-hinweis">
			Diese Stellen werden je Empfänger ersetzt: {anrede}, {vorname},
			{nachname}, {kursart}, {termin}.
		</p>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.benachrichtigen.sende')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="kennung" value="<?php p($_['kennung']); ?>">

			<p class="rf-feld">
				<label for="rf-betreff">Betreff</label>
				<input type="text" id="rf-betreff" name="betreff"
				       value="<?php p($_['betreff']); ?>" required>
			</p>
			<p class="rf-feld">
				<label for="rf-text-angemeldete">Text an die Angemeldeten</label>
				<textarea id="rf-text-angemeldete" name="textAngemeldete" rows="8"><?php p($_['textAngemeldete']); ?></textarea>
			</p>
			<p class="rf-feld">
				<label for="rf-text-wartende">Text an die Warteliste</label>
				<textarea id="rf-text-wartende" name="textWartende" rows="8"><?php p($_['textWartende']); ?></textarea>
			</p>

			<span class="rf-knopfzeile">
				<a class="button"
				   href="<?php p($urls->linkToRoute('radfahrschule.kurs.zeige',
					   ['id' => $_['id']])); ?>">Zurück</a>
				<button type="submit" class="primary">Jetzt verschicken</button>
			</span>
		</form>
	</div>
</div>
