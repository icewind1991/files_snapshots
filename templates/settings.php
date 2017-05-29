<?php
script('files_snapshots', 'settings');
style('files_snapshots', 'settings');

/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<form id="files_snapshots" class="section" action="#" method="post">
	<h2><?php p($l->t('Snapshots')); ?></h2>

	<table class="settings">
		<tr>
			<td>
				<label for="format">Snapshot format</label>
			</td>
			<td>
				<input id="format" value="<?php p($_['snapshot_format']) ?>"
					   placeholder="/path/to/snapshots/%snapshot%/data"/>
			</td>
		</tr>
		<tr>
			<td>
				<label for="date_format">Date format</label>
			</td>
			<td>
				<input id="date_format" value="<?php p($_['date_format']) ?>"/>
			</td>
		</tr>
		<tr>
			<td>
				<input type="submit" value="Save"/>
			</td>
		</tr>
	</table>
</form>
