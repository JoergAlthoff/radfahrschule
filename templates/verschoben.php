<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Kurs verschoben</h2>

		<p>Der Kurs heißt jetzt <strong><?php p($_['neueKennung']); ?></strong>.
			Die öffentlichen Links haben sich nicht geändert.</p>

		<?php if ($_['anmeldungen'] > 0) { ?>
			<p class="rf-warnung">
				<?php p($_['anmeldungen']); ?> Angemeldete wissen noch nichts
				vom neuen Termin. Bitte in Nextcloud anschreiben — der Dienst
				kann das nicht.
			</p>
		<?php } ?>

		<?php /* Wie auf der Erfolgsseite: Der Satz gehoert dem Betreiber. */ ?>
		<?php if ($_['terminportalHinweis'] !== '') { ?>
			<p class="rf-hinweis"><?php p($_['terminportalHinweis']); ?></p>
		<?php } ?>

		<p class="rf-knopfzeile">
			<a class="button"
			   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Zur Übersicht</a>
		</p>
	</div>
</div>
