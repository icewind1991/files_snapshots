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
use OCP\IAppConfig;
use OCP\IRequest;

class AdminController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly SnapshotManager $snapshotManager,
	) {
		parent::__construct($appName, $request);
	}

	public function testSettings(string $snapshotFormat, string $dateFormat): array {
		$manager = clone $this->snapshotManager;
		$manager->setDateFormat($dateFormat);
		$manager->setSnapshotFormat($snapshotFormat);

		$snapshots = iterator_to_array($manager->listAllSnapshots());
		usort($snapshots, function (Snapshot $a, Snapshot $b) {
			return strcmp($a->getName(), $b->getName());
		});

		$names = array_map(function (Snapshot $snapshot) {
			return $snapshot->getName();
		}, $snapshots);

		$dates = array_map(function (Snapshot $snapshot) use ($dateFormat) {
			return $snapshot->getSnapshotDate()->format('Y-m-d H:i:s');
		}, $snapshots);

		return array_combine($names, $dates);
	}

	/**
	 * @return list<string>
	 */
	private static function splitLines(string $text): array {
		return array_values(array_filter(array_map('trim', explode("\n", $text)), fn (string $line) => $line !== ''));
	}

	public function save(
		string $snapshotFormat,
		string $dateFormat,
		array $mountFormats = [],
		bool $autodetect = false,
		string $autodetectPatterns = '',
	): array {
		$mountFormats = SnapshotManager::sanitizeMountFormats($mountFormats);
		$patterns = SnapshotManager::sanitizeAutodetectPatterns(self::splitLines($autodetectPatterns));
		$this->appConfig->setValueString('files_snapshots', 'snap_format', $snapshotFormat);
		$this->appConfig->setValueString('files_snapshots', 'date_format', $dateFormat);
		$this->appConfig->setValueArray('files_snapshots', 'mount_formats', $mountFormats);
		$this->appConfig->setValueBool('files_snapshots', 'autodetect', $autodetect);
		$this->appConfig->setValueArray('files_snapshots', 'autodetect_patterns', $patterns);
		return [
			'snapshotFormat' => $snapshotFormat,
			'dateFormat' => $dateFormat,
			'mountFormats' => $mountFormats,
			'autodetect' => $autodetect,
			'autodetectPatterns' => $patterns,
		];
	}
}
