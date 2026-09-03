<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Kurs wirklich löschen?</h2>

		<p class="rf-hinweis"><?php p($_['kennung']); ?> — <?php p($_['kurstag']); ?></p>

		<p class="rf-warnung">
			Es ist noch nichts gelöscht. Diese Formulare gehen weg, mit allem,
			was Menschen dort eingetragen haben. Nextcloud hat keinen
			Papierkorb — das lässt sich nicht zurückholen.
		</p>

		<div class="rf-tabellenrahmen">
			<table class="rf-tabelle">
				<thead>
					<tr>
						<th>Formular</th>
						<th class="rf-zahl">Abgaben</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($_['zeilen'] as $zeile) { ?>
					<tr>
						<td data-spalte="Formular"><?php p($zeile['beschriftung']); ?></td>
						<td class="rf-zahl" data-spalte="Abgaben"><?php p($zeile['abgaben']); ?></td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>

		<?php if ($_['fehlendeHaelfte'] !== '') { ?>
			<p class="rf-warnung">
				Die <?php p($_['fehlendeHaelfte']); ?> fehlt diesem Kurs. Sie
				bleibt in Nextcloud stehen, samt ihren Einträgen.
			</p>
		<?php } ?>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.kurs.loesche')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="kennung" value="<?php p($_['kennung']); ?>">

			<span class="rf-knopfzeile">
				<?php /* Der Weg zurueck ist ein Link: Die Kursseite ist eine
				         GET-Route und haengt an der Formularnummer. */ ?>
				<a class="button"
				   href="<?php p($urls->linkToRoute('radfahrschule.kurs.zeige',
					   ['id' => $_['id']])); ?>">Zurück</a>
				<button type="submit" class="error">Endgültig löschen</button>
			</span>
		</form>
	</div>
</div>
