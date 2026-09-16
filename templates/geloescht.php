<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Kurs gelöscht</h2>

		<?php if ($_['fehlend'] !== '') { ?>
			<p>Von <?php p($_['kennung']); ?> ist gelöscht:</p>
			<ul>
				<?php foreach ($_['geloeschte'] as $beschriftung) { ?>
					<li><?php p($beschriftung); ?></li>
				<?php } ?>
			</ul>
			<p class="rf-warnung">
				<strong>Die <?php p($_['fehlend']); ?> war nicht auffindbar.</strong>
				Sie steht wahrscheinlich noch in Nextcloud, unter einem anderen
				Namen — mit ihren Einträgen. Bitte dort nachsehen und von Hand
				löschen.
			</p>
		<?php } else { ?>
			<p><?php p($_['kennung']); ?> ist samt Anmeldedaten aus Nextcloud
				entfernt.</p>
		<?php } ?>

		<?php if ($_['absage'] !== '') { ?>
			<h3>Absage</h3>
			<p class="rf-hinweis"><?php p($_['absage']); ?></p>

			<?php if ($_['gescheitert'] !== []) { ?>
				<div class="rf-warnung">
					<p>An diese Einträge ging keine Absage hinaus. Die Formulare
						sind gelöscht, ihre Adressen gibt es nicht mehr:</p>
					<ul>
						<?php foreach ($_['gescheitert'] as $name) { ?>
							<li><?php p($name); ?></li>
						<?php } ?>
					</ul>
				</div>
			<?php } ?>
		<?php } ?>

		<p class="rf-knopfzeile">
			<a class="button"
			   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Zur Übersicht</a>
		</p>
	</div>
</div>
