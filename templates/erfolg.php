<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Der Kurs steht</h2>

		<div class="rf-tabellenrahmen">
			<table class="rf-tabelle">
				<tbody>
					<tr>
						<th><?php p($_['titelAnmeldung']); ?></th>
						<td><a href="<?php p($_['anmeldungLink']); ?>"><?php
							p($_['anmeldungLink']); ?></a></td>
					</tr>
					<tr>
						<th><?php p($_['titelWarteliste']); ?></th>
						<td><a href="<?php p($_['wartelisteLink']); ?>"><?php
							p($_['wartelisteLink']); ?></a></td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php /* Der Satz steht in den Einstellungen. Ist keiner
		         eingetragen, entfaellt der Absatz - nicht ein leerer
		         Kasten. */ ?>
		<?php if ($_['terminportalHinweis'] !== '') { ?>
			<p class="rf-hinweis"><?php p($_['terminportalHinweis']); ?></p>
		<?php } ?>

		<p class="rf-knopfzeile">
			<a class="button"
			   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Zur Übersicht</a>
		</p>
	</div>
</div>
