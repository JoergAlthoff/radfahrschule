<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>Kurse der Radfahrschule</h2>

		<p class="rf-knopfzeile">
			<a class="button primary"
			   href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)
			   	->linkToRoute('radfahrschule.anlegen.formular')); ?>">Neuen Kurs anlegen</a>
		</p>

		<?php if ($_['fehler'] !== '') { ?>
			<p class="rf-warnung"><?php p($_['fehler']); ?></p>
		<?php } elseif ($_['kurse'] === []) { ?>
			<p class="rf-hinweis">Es gibt zurzeit keine Kurse.</p>
		<?php } else { ?>
			<div class="rf-tabellenrahmen">
				<table class="rf-tabelle">
					<thead>
						<tr>
							<th>Kurs</th>
							<th class="rf-zahl">Anmeldungen</th>
							<th class="rf-zahl">Warteliste</th>
							<th>Frist</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ($_['kurse'] as $kurs) { ?>
						<tr>
							<td data-spalte="Kurs">
								<a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)
									->linkToRoute('radfahrschule.kurs.zeige',
										['id' => $kurs['id']])); ?>"><?php
									p($kurs['kennung']); ?></a>
							</td>
							<td class="rf-zahl" data-spalte="Anmeldungen"><?php
								p($kurs['anmeldungen']); ?></td>
							<td class="rf-zahl" data-spalte="Warteliste"><?php
								p($kurs['wartende']); ?></td>
							<td data-spalte="Frist"><?php p($kurs['frist']); ?></td>
						</tr>
					<?php } ?>
					</tbody>
				</table>
			</div>
		<?php } ?>
	</div>
</div>
