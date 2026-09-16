<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Nachricht verschickt</h2>

		<p class="rf-hinweis"><?php p($_['kennung']); ?></p>
		<p class="rf-hinweis"><?php p($_['satz']); ?></p>

		<?php /* Die Namen warten in der Sitzung, bis diese Seite sie einmal
		         gezeigt hat, und sind danach dort weg. In Forms stehen sie ohnehin. */ ?>
		<?php if ($_['gescheitert'] !== []) { ?>
			<div class="rf-warnung">
				<p>An diese Einträge ging keine Mail hinaus. Ihre Adressen stehen in Forms:</p>
				<ul>
					<?php foreach ($_['gescheitert'] as $name) { ?>
						<li><?php p($name); ?></li>
					<?php } ?>
				</ul>
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
