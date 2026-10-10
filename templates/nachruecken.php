<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
$ankreuzbar = $_['hindernis'] === '';
$mitKnopf = $ankreuzbar && $_['zeilen'] !== [];
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Nachrücken lassen</h2>

		<p class="rf-hinweis"><?php p($_['kennung']); ?></p>
		<?php if ($_['platzsatz'] !== '') { ?>
			<p class="rf-hinweis"><?php p($_['platzsatz']); ?></p>
		<?php } ?>

		<?php if ($_['hindernis'] !== '') { ?>
			<p class="rf-warnung"><?php p($_['hindernis']); ?></p>
		<?php } ?>
		<?php if ($_['fehler'] !== '') { ?>
			<p class="rf-warnung"><?php p($_['fehler']); ?></p>
		<?php } ?>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.nachruecken.ausfuehren')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="kennung" value="<?php p($_['kennung']); ?>">

			<?php if ($_['zeilen'] === []) { ?>
				<p class="rf-hinweis">Auf der Warteliste steht niemand.</p>
			<?php } else { ?>
				<div class="rf-tabellenrahmen">
					<table class="rf-tabelle">
						<thead>
							<tr>
								<th>Name</th>
								<th>Eingetragen am</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($_['zeilen'] as $zeile) { ?>
							<tr>
								<td data-spalte="Name">
									<?php if ($ankreuzbar) { ?>
										<input type="checkbox" name="abgaben[]"
										       id="rf-abgabe-<?php p($zeile['nummer']); ?>"
										       value="<?php p($zeile['nummer']); ?>"
										       <?php if ($zeile['angekreuzt']) { ?>checked<?php } ?>>
										<label for="rf-abgabe-<?php p($zeile['nummer']); ?>"><?php p($zeile['name']); ?></label>
									<?php } else { ?>
										<?php p($zeile['name']); ?>
									<?php } ?>
								</td>
								<td data-spalte="Eingetragen am"><?php p($zeile['eingetragen']); ?></td>
							</tr>
						<?php } ?>
						</tbody>
					</table>
				</div>
			<?php } ?>

			<?php if ($mitKnopf) { ?>
				<p class="rf-hinweis">
					Wer nachrückt, bekommt die Bestätigungsmail der Anmeldung.
					Das lässt sich nicht zurücknehmen.
				</p>
			<?php } ?>

			<span class="rf-knopfzeile">
				<a class="button"
				   href="<?php p($urls->linkToRoute('radfahrschule.kurs.zeige',
					   ['id' => $_['id']])); ?>">Zurück</a>
				<?php if ($mitKnopf) { ?>
					<button type="submit" class="primary">Nachrücken lassen</button>
				<?php } ?>
			</span>
		</form>
	</div>
</div>
