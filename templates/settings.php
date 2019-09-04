<?php
script('files_snapshots', 'settings');
style('files_snapshots', 'settings');

/** @var \OCP\IL10N $l */
/** @var array $_ */
?>

<form id="files_snapshots" class="section" action="#" method="post">
	<h2><?php p($l->t('Snapshots')); ?></h2>
	<p class="settings-hint"><?php p($l->t('Access filesystem snapshots from Nextcloud.')); ?></p>
	<div class="settings">
		<div>
			<label>
				<span><?php p($l->t('Snapshot format')); ?></span>
				<input type="text" id="format"
					   value="<?php p($_['snapshot_format']) ?>"
					   placeholder="/path/to/snapshots/%snapshot%/data"/>
			</label>
		</div>
		<div>
			<label>
				<span><?php p($l->t('Date format')); ?></span>
				<input type="text"
					   id="date_format" value="<?php p($_['date_format']) ?>"
					   placeholder="Y-m-d_H:i:s"/>
				<a target="_blank" rel="noreferrer noopener" class="icon-info" title="Open documentation" href="https://www.php.net/manual/en/datetime.createfromformat.php#refsect1-datetime.createfromformat-parameters"></a>
			</label>
		</div>
		<div>
			<input type="submit" value="<?php p($l->t('Save')); ?>"/>
		</div>
	</div>
	<h2><?php p($l->t('Discovered Snapshots')); ?></h2>
	<div class="loading"></div>
	<table class="result grid hidden">
		<thead>
			<tr>
				<th><?php p($l->t('Snapshot')); ?></th>
				<th><?php p($l->t('Date')); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
			</tr>
		</tbody>
	</table>
</form>
