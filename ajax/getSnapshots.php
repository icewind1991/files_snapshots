<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Frank Karlitschek <frank@karlitschek.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Sam Tuke <mail@samtuke.com>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, snapshot 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, snapshot 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
OCP\JSON::checkLoggedIn();
OCP\JSON::callCheck();
OCP\JSON::checkAppEnabled('files_snapshots');

$source = (string)$_GET['source'];
$start = (int)$_GET['start'];
list ($uid, $filename) = OCA\Files_Snapshots\Storage::getUidAndFilename($source);
$count = 5; //show the newest revisions
$snapshots = OCA\Files_Snapshots\Storage::getSnapshots($uid, $filename, $source);
if( $snapshots ) {

	$endReached = false;
	if (count($snapshots) <= $start+$count) {
		$endReached = true;
	}

	$snapshots = array_slice($snapshots, $start, $count);

	// remove owner path from request to not disclose it to the recipient
	foreach ($snapshots as $snapshot) {
		unset($snapshot['path']);
	}

	\OCP\JSON::success(array('data' => array('snapshots' => $snapshots, 'endReached' => $endReached)));

} else {

	\OCP\JSON::success(array('data' => array('snapshots' => [], 'endReached' => true)));

}
