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

			<h3>Absage an die Eingetragenen</h3>

			<?php if ($_['grundOhneAbsage'] !== '') { ?>
				<p class="rf-hinweis"><?php p($_['grundOhneAbsage']); ?></p>
			<?php } else { ?>
				<?php if ($_['fehler'] !== '') { ?>
					<p class="rf-warnung"><?php p($_['fehler']); ?></p>
				<?php } ?>

				<p class="rf-hinweis">
					Die Absage geht erst hinaus, wenn die Formulare gelöscht sind.
					Jeder Eintrag in der Anmeldung und auf der Warteliste bekommt
					eine eigene Mail. Bleibt ein Text leer,
					bekommt diese Liste nichts. Bleiben beide leer, wird ohne
					Absage gelöscht.
				</p>
				<p class="rf-hinweis">
					Diese Stellen werden je Empfänger ersetzt: {anrede}, {vorname},
					{nachname}, {kursart}, {termin}.
				</p>

				<p class="rf-feld">
					<label for="rf-absage-betreff">Betreff</label>
					<input type="text" id="rf-absage-betreff" name="betreff"
					       value="<?php p($_['betreff']); ?>">
				</p>
				<p class="rf-feld">
					<label for="rf-absage-angemeldete">Text an die Angemeldeten</label>
					<textarea id="rf-absage-angemeldete" name="textAngemeldete" rows="8"><?php p($_['textAngemeldete']); ?></textarea>
				</p>
				<p class="rf-feld">
					<label for="rf-absage-wartende">Text an die Warteliste</label>
					<textarea id="rf-absage-wartende" name="textWartende" rows="8"><?php p($_['textWartende']); ?></textarea>
				</p>
			<?php } ?>

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
