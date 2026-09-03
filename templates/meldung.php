<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2><?php p($_['titel']); ?></h2>

		<?php if ($_['vorformatiert']) { ?>
			<pre class="rf-abbruch"><?php p($_['meldung']); ?></pre>
		<?php } else { ?>
			<p class="rf-hinweis"><?php p($_['meldung']); ?></p>
		<?php } ?>

		<p class="rf-knopfzeile">
			<a class="button"
			   href="<?php p($urls->linkToRoute('radfahrschule.uebersicht.index')); ?>">Zur Übersicht</a>
		</p>
	</div>
</div>
