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

use OCA\Files_Snapshots\ExternalStorages;
use OCA\Files_Snapshots\Snapshot;
use OCA\Files_Snapshots\SnapshotLocation;
use OCA\Files_Snapshots\SnapshotManager;
use OCP\AppFramework\Controller;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;

class AdminController extends Controller {
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $appConfig,
		private readonly SnapshotManager $snapshotManager,
		private readonly ExternalStorages $externalStorages,
		private readonly IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List the snapshots for a snapshot format
	 */
	public function testSettings(string $snapshotFormat, string $dateFormat): array {
		$manager = clone $this->snapshotManager;
		$manager->setDateFormat($dateFormat);
		$manager->setSnapshotFormat($snapshotFormat);

		return $this->formatSnapshots(iterator_to_array($manager->listAllSnapshots(), false));
	}

	private function buildManager(
		string $snapshotFormat,
		string $dateFormat,
		array $mountFormats,
		bool $autodetect,
		string $autodetectPatterns,
	): SnapshotManager {
		$manager = clone $this->snapshotManager;
		$manager->setDateFormat($dateFormat);
		$manager->setSnapshotFormat($snapshotFormat);
		$manager->setMountFormats($mountFormats);
		$manager->setAutodetect($autodetect);
		$manager->setAutodetectPatterns(self::splitLines($autodetectPatterns));
		return $manager;
	}

	/**
	 * Show where the snapshots for a path on disk would be found with the given (unsaved) settings
	 */
	public function testPath(
		string $path,
		string $snapshotFormat,
		string $dateFormat,
		array $mountFormats = [],
		bool $autodetect = false,
		string $autodetectPatterns = '',
	): array {
		$manager = $this->buildManager($snapshotFormat, $dateFormat, $mountFormats, $autodetect, $autodetectPatterns);

		$location = $manager->resolve($path);
		if ($location === null) {
			return ['found' => false];
		}
		$snapshots = iterator_to_array($location->format->listSnapshots(), false);
		$withFile = array_values(array_filter($snapshots, fn (Snapshot $snapshot) => $snapshot->hasFile($location->relativePath)));
		return [
			'found' => true,
			'relativePath' => $location->relativePath,
			'example' => $withFile ? $withFile[0]->getFilePath($location->relativePath) : null,
			'snapshotCount' => count($snapshots),
			'snapshots' => $this->formatSnapshots($withFile),
			'versionCount' => count($manager->listSnapshotsForLocation($location)),
		];
	}

	/**
	 * List the data directory, local external storages and additional locations, with where their snapshots are found
	 */
	public function listLocations(
		string $snapshotFormat,
		string $dateFormat,
		array $mountFormats = [],
		bool $autodetect = false,
		string $autodetectPatterns = '',
	): array {
		$manager = $this->buildManager($snapshotFormat, $dateFormat, $mountFormats, $autodetect, $autodetectPatterns);

		$locations = [];
		$dataDirectory = $manager->getDataDirectory();
		if ($dataDirectory !== null) {
			$locations[] = $this->describeLocation($manager, 'datadir', $this->l10n->t('Data directory'), $dataDirectory);
		}
		foreach ($this->externalStorages->getLocalStorages() as $storage) {
			$locations[] = $this->describeLocation($manager, 'external', $storage['mountPoint'], $storage['datadir']);
		}
		foreach ($manager->getMountFormats() as $mountFormat) {
			$sources = SnapshotManager::expandSource($mountFormat['source']);
			if (!$sources) {
				// placeholder that doesn't match any folder
				$locations[] = ['type' => 'additional', 'label' => $this->l10n->t('Additional location'), 'source' => $mountFormat['source'], 'found' => false];
			}
			foreach ($sources as $source) {
				$locations[] = $this->describeLocation($manager, 'additional', $this->l10n->t('Additional location'), $source);
			}
		}
		return $locations;
	}

	/**
	 * @return array{type: string, label: string, source: string, found: bool, format?: string, relativePath?: string, autodetected?: bool, snapshotCount?: int}
	 */
	private function describeLocation(SnapshotManager $manager, string $type, string $label, string $source): array {
		$result = ['type' => $type, 'label' => $label, 'source' => $source, 'found' => false];
		$location = $manager->resolve($source);
		if ($location instanceof SnapshotLocation) {
			// only count the snapshots here, checking which ones contain the location is done when listing them
			$count = $location->format->countSnapshots();
			$result['found'] = true;
			$result['format'] = $location->format->getFormat();
			$result['relativePath'] = $location->relativePath;
			$result['autodetected'] = $location->autodetected;
			$result['snapshotCount'] = $count;
		}
		return $result;
	}

	/**
	 * @param Snapshot[] $snapshots
	 * @return array<string, string> snapshot name => date, sorted by name
	 */
	private function formatSnapshots(array $snapshots): array {
		usort($snapshots, function (Snapshot $a, Snapshot $b) {
			return strcmp($a->getName(), $b->getName());
		});
		$result = [];
		foreach ($snapshots as $snapshot) {
			$result[$snapshot->getName()] = $snapshot->getSnapshotDate()->format('Y-m-d H:i:s');
		}
		return $result;
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
