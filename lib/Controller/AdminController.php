<?php
/**
 * @copyright Copyright (c) 2017 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Files_Snapshots\Controller;

use OCA\Files_Snapshots\Snapshot;
use OCA\Files_Snapshots\SnapshotManager;
use OCP\AppFramework\Controller;
use OCP\IConfig;
use OCP\IRequest;

class AdminController extends Controller {
	/** @var SnapshotManager */
	private $snapshotManager;

	/** @var IConfig */
	private $config;

	public function __construct($appName,
		IRequest $request,
		SnapshotManager $snapshotManager,
		IConfig $config
	) {
		parent::__construct($appName, $request);
		$this->snapshotManager = $snapshotManager;
		$this->config = $config;
	}

	/**
	 * @param string $snapshotFormat
	 * @param string $dateFormat
	 * @return array
	 */
	public function testSettings($snapshotFormat, $dateFormat) {
		$manager = new SnapshotManager($snapshotFormat, $dateFormat);
		$snapshots = iterator_to_array($manager->listAllSnapshots());
		usort($snapshots, function (Snapshot $a, Snapshot $b) {
			return strcmp($a->getName(), $b->getName());
		});

		$names = array_map(function (Snapshot $snapshot) {
			return $snapshot->getName();
		}, $snapshots);

		$dates = array_map(function (Snapshot $snapshot) use ($dateFormat) {
			$date = $snapshot->getSnapshotDate();
			return $date ? $date->format('Y-m-d H:i:s') : null;
		}, $snapshots);

		return array_combine($names, $dates);
	}

	/**
	 * @param string $snapshotFormat
	 * @param string $dateFormat
	 */
	public function save($snapshotFormat, $dateFormat) {
		$this->config->setAppValue('files_snapshots', 'snap_format', $snapshotFormat);
		$this->config->setAppValue('files_snapshots', 'date_format', $dateFormat);
		return [$snapshotFormat];
	}
}
