<?php
declare(strict_types=1);
/** @var array $_ */
\OCP\Util::addStyle('radfahrschule', 'radfahrschule');
$urls = \OCP\Server::get(\OCP\IURLGenerator::class);
?>
<div id="app-content">
	<div class="rf-abschnitt">
		<h2>So würde der Kurs verschoben</h2>

		<?php if ($_['hinweis'] !== '') { ?>
			<p class="rf-warnung"><?php p($_['hinweis']); ?></p>
		<?php } ?>

		<p class="rf-hinweis">
			Es ist noch nichts geschrieben. Bitte beide Spalten vergleichen —
			besonders die Jahreszahl.
		</p>

		<div class="rf-tabellenrahmen">
			<table class="rf-tabelle">
				<thead>
					<tr>
						<th></th>
						<th>bisher</th>
						<th>neu</th>
					</tr>
				</thead>
				<tbody>
					<?php /* Nur was es wirklich gibt: Ein leerer alter Titel
					        heisst, dass diese Haelfte dem Kurs fehlt - und
					        das Verschieben fasst sie dann auch nicht an. */ ?>
					<?php if ($_['alterTitelAnmeldung'] !== '') { ?>
						<tr>
							<td data-spalte="Feld">Anmeldung</td>
							<td data-spalte="bisher"><?php p($_['alterTitelAnmeldung']); ?></td>
							<td data-spalte="neu"><?php p($_['neuerTitelAnmeldung']); ?></td>
						</tr>
					<?php } ?>
					<?php if ($_['alterTitelWarteliste'] !== '') { ?>
						<tr>
							<td data-spalte="Feld">Warteliste</td>
							<td data-spalte="bisher"><?php p($_['alterTitelWarteliste']); ?></td>
							<td data-spalte="neu"><?php p($_['neuerTitelWarteliste']); ?></td>
						</tr>
					<?php } ?>
					<tr>
						<td data-spalte="Feld">Letzter Kurstag</td>
						<td data-spalte="bisher"><?php p($_['alterKurstag']); ?></td>
						<td data-spalte="neu"><?php p($_['bisLesbar']); ?></td>
					</tr>
					<tr>
						<td data-spalte="Feld">Anmeldeschluss</td>
						<td data-spalte="bisher"><?php p($_['alterAnmeldeschluss']); ?></td>
						<td data-spalte="neu"><?php p($_['anmeldeschlussLesbar']); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<?php if ($_['anmeldungen'] > 0) { ?>
			<p class="rf-warnung">
				<?php p($_['anmeldungen']); ?> Personen sind bereits angemeldet.
				Sie bleiben es — aber sie erfahren vom neuen Termin nur, wenn
				jemand sie anschreibt.
			</p>
		<?php } ?>

		<form method="post"
		      action="<?php p($urls->linkToRoute('radfahrschule.verschieben.ausfuehren')); ?>">
			<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
			<input type="hidden" name="kennung" value="<?php p($_['alteKennung']); ?>">
			<input type="hidden" name="von" value="<?php p($_['vonIso']); ?>">
			<input type="hidden" name="bis" value="<?php p($_['bisIso']); ?>">
			<input type="hidden" name="anmeldeschluss" value="<?php p($_['anmeldeschlussIso']); ?>">

			<span class="rf-knopfzeile">
				<button type="submit" class="primary">Jetzt verschieben</button>
				<?php /* Dasselbe Formular, anderes Ziel - siehe kurs.php. */ ?>
				<button type="submit"
				        formaction="<?php p($urls->linkToRoute('radfahrschule.verschieben.formular')); ?>">Zurück</button>
			</span>
		</form>
	</div>
</div>
