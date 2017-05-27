<?php
script('files_snapshots', 'settings');

/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<form id="files_snapshots" class="section" action="#" method="post">
	<h2><?php p($l->t('Snapshots')); ?></h2>

	<p>
		<label for="format">Snapshot format</label>
		<input id="format" value="<?php p($_['snapshot_format']) ?>"
			   placeholder="/path/to/snapshots/%snapshot%/data"/>
	</p>
	<p>
		<label for="date_format">Date format</label>
		<input id="date_format" value="<?php p($_['date_format']) ?>"/>
	</p>
	<p>
		<input type="submit" value="Save"/>
	</p>
</form>
