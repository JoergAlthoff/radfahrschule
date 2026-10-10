<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Nachgerückt</h2>

		<p class="rf-hinweis"><?php p($_['kennung']); ?></p>
		<p class="rf-hinweis"><?php p($_['satz']); ?></p>

		<?php /* Die Namen warten in der Sitzung, bis diese Seite sie einmal
		         gezeigt hat, und sind danach dort weg. In Forms stehen sie ohnehin. */ ?>
		<?php if ($_['nachgerueckt'] !== []) { ?>
			<ul>
				<?php foreach ($_['nachgerueckt'] as $name) { ?>
					<li><?php p($name); ?></li>
				<?php } ?>
			</ul>
		<?php } ?>

		<?php if ($_['stoerung'] !== '') { ?>
			<div class="rf-warnung">
				<p><?php p($_['stoerung']); ?></p>
				<?php if ($_['uebrig'] !== []) { ?>
					<p>Deshalb nicht mehr drangekommen und weiter auf der Warteliste:</p>
					<ul>
						<?php foreach ($_['uebrig'] as $name) { ?>
							<li><?php p($name); ?></li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		<?php } ?>

		<p class="rf-knopfzeile">
			<a class="button"
			   href="<?php p($urls->linkToRoute('radfahrschule.kurs.zeige',
				   ['id' => $_['id']])); ?>">Zum Kurs</a>
			<a class="button"
			   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Zur Übersicht</a>
		</p>
	</div>
</div>
