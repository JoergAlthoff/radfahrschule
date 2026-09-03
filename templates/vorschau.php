<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>So würde der Kurs aussehen</h2>

		<p class="rf-hinweis">
			Es ist noch nichts geschrieben. In Nextcloud lässt sich ein
			Formular nicht zurückholen — deshalb hier bitte genau hinsehen,
			besonders auf die Jahreszahl.
		</p>

		<div class="rf-tabellenrahmen">
			<table class="rf-tabelle">
				<tbody>
					<tr>
						<th>Anmeldung</th>
						<td><?php p($_['titelAnmeldung']); ?></td>
					</tr>
					<tr>
						<th>Warteliste</th>
						<td><?php p($_['titelWarteliste']); ?></td>
					</tr>
					<tr>
						<th>Termin im Text</th>
						<td><?php p($_['termin']); ?></td>
					</tr>
					<tr>
						<th>Anmeldeschluss</th>
						<td><?php p($_['anmeldeschlussLesbar']); ?></td>
					</tr>
					<tr>
						<th>Plätze</th>
						<td><?php p($_['plaetze']); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.anlegen.ausfuehren')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="vorlage_anmeldung" value="<?php p($_['vorlageAnmeldung']); ?>">
			<input type="hidden" name="vorlage_warteliste" value="<?php p($_['vorlageWarteliste']); ?>">
			<input type="hidden" name="von" value="<?php p($_['vonIso']); ?>">
			<input type="hidden" name="bis" value="<?php p($_['bisIso']); ?>">
			<input type="hidden" name="anmeldeschluss" value="<?php p($_['anmeldeschlussIso']); ?>">
			<input type="hidden" name="plaetze" value="<?php p($_['plaetze']); ?>">

			<span class="rf-knopfzeile">
				<button type="submit" class="primary">Jetzt anlegen</button>
				<?php /* Die Werte reisen als Query mit: Ohne sie stuende das
				         Formular wieder leer da. Ein Knopf mit formaction
				         ginge nicht - die Route nimmt nur GET. */ ?>
				<a class="button"
				   href="<?php p($urls->linkToRoute('radfahrschule.anlegen.formular', [
				   	'von' => $_['vonIso'],
				   	'bis' => $_['bisIso'],
				   	'anmeldeschluss' => $_['anmeldeschlussIso'],
				   	'plaetze' => $_['plaetze'],
				   ])); ?>">Zurück</a>
			</span>
		</form>
	</div>
</div>
