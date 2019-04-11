<?php
script('files_snapshots', 'settings');
style('files_snapshots', 'settings');

/** @var \OCP\IL10N $l */
/** @var array $_ */
?>
<form id="files_snapshots" class="section" action="#" method="post">
	<h2><?php p($l->t('Snapshots')); ?></h2>

	<table class="settings">
		<tbody>
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
                <label for="user_format">User format</label>
            </td>
            <td>
                <input id="user_format" value="<?php p($_['user_format']) ?>"/>
            </td>
        </tr>
		<tr>
			<td>
				<input type="submit" value="Save"/>
			</td>
		</tr>
		</tbody>
	</table>
	<h2>Discovered Snapshots</h2>
	<div class="loading"></div>
	<table class="result grid hidden">
		<thead>
		<tr>
			<th><?php p($l->t('Snapshot')); ?></th>
			<th><?php p($l->t('Date')); ?></th>
            <th><?php p($l->t('User')); ?></th>
		</tr>
		</thead>
		<tbody>
		<tr>
		</tr>
		</tbody>
	</table>
</form>
