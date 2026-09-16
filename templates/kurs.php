<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2><?php p($_['kennung']); ?></h2>

		<p class="rf-hinweis"><?php p($_['kurstag']); ?> <?php p($_['frist']); ?></p>

		<?php if ($_['fehlendeHaelfte'] !== '') { ?>
			<p class="rf-warnung">
				Zu diesem Kurs fehlt die <?php p($_['fehlendeHaelfte']); ?>.
				Sie steht wahrscheinlich noch in Nextcloud, unter einem anderen
				Namen — mit ihren Einträgen. Beim Löschen bleibt sie stehen.
			</p>
		<?php } ?>

		<?php if ($_['doppelt']) { ?>
			<p class="rf-warnung">
				Dieser Kurs hat mehr Formulare, als er haben sollte. Vermutlich
				wurde er zweimal angelegt. Beim Löschen gehen alle weg.
			</p>
		<?php } ?>

		<div class="rf-tabellenrahmen">
			<table class="rf-tabelle">
				<thead>
					<tr>
						<th>Formular</th>
						<th class="rf-zahl">Abgaben</th>
						<th>Öffentlicher Link</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($_['zeilen'] as $zeile) { ?>
					<tr>
						<td data-spalte="Formular"><?php p($zeile['beschriftung']); ?></td>
						<td class="rf-zahl" data-spalte="Abgaben"><?php p($zeile['abgaben']); ?></td>
						<td data-spalte="Öffentlicher Link">
							<?php if ($zeile['link'] === '') { ?>
								<span class="rf-hinweis">keiner</span>
							<?php } else { ?>
								<a href="<?php p($zeile['link']); ?>"><?php p($zeile['link']); ?></a>
							<?php } ?>
						</td>
					</tr>
				<?php } ?>
				</tbody>
			</table>
		</div>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.kurs.nachfrage')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="kennung" value="<?php p($_['kennung']); ?>">

			<?php /* Zwei Ziele in EINEM Formular, unterschieden ueber
			         formaction. Zwei getrennte Formulare ergaeben zwei
			         Bloecke untereinander; die Knoepfe gehoeren aber in
			         eine Zeile. Ein Link geht nicht: Die Kennung traegt
			         Schraegstriche und passt in keinen Pfad. */ ?>
			<span class="rf-knopfzeile">
				<button type="submit"
				        formaction="<?php p($urls->linkToRoute('radfahrschule.benachrichtigen.formular')); ?>">Nachricht schreiben</button>
				<button type="submit"
				        formaction="<?php p($urls->linkToRoute('radfahrschule.verschieben.formular')); ?>">Termin verschieben</button>
				<button type="submit" class="error">Kurs löschen</button>
				<a class="button"
				   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Zurück</a>
			</span>
		</form>
	</div>
</div>
