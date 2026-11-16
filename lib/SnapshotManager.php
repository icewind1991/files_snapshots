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

namespace OCA\Files_Snapshots;

use OCP\IAppConfig;
use OCP\IConfig;
use Traversable;

class SnapshotManager {
	private string $rawSnapshotFormat = '';
	private SnapshotFormat $snapshotFormat;
	private string $dateFormat;
	private ?string $dataDirectory;
	/** @var list<array{source: string, format: string}> */
	private array $mountFormats = [];

	/**
	 * Placeholder in the source of an additional location that matches a single folder name, such as a user id,
	 * the matched name is substituted into the snapshot format.
	 */
	public const USER_PLACEHOLDER = '%user%';

	public function __construct(IAppConfig $appConfig, IConfig $config) {
		$this->setDataDirectory($config->getSystemValueString('datadirectory'));
		$this->setDateFormat($appConfig->getValueString('files_snapshots', 'date_format', 'Y-m-d_H:i:s'));
		$this->setSnapshotFormat($appConfig->getValueString('files_snapshots', 'snap_format'));
		$this->setMountFormats($appConfig->getValueArray('files_snapshots', 'mount_formats'));
	}

	public function setSnapshotFormat(string $snapshotFormat): void {
		$this->rawSnapshotFormat = $snapshotFormat;
		$this->snapshotFormat = new SnapshotFormat($snapshotFormat, $this->dateFormat);
	}

	public function setDateFormat(string $dateFormat): void {
		$this->dateFormat = $dateFormat;
		$this->snapshotFormat = new SnapshotFormat($this->rawSnapshotFormat, $dateFormat);
	}

	/**
	 * @param array $mountFormats list of ['source' => '/path/on/disk', 'format' => '/path/to/snapshots/%snapshot%/sub']
	 */
	public function setMountFormats(array $mountFormats): void {
		$this->mountFormats = self::sanitizeMountFormats($mountFormats);
	}

	/**
	 * @return list<array{source: string, format: string}>
	 */
	public static function sanitizeMountFormats(array $mountFormats): array {
		$result = [];
		foreach ($mountFormats as $mountFormat) {
			if (!is_array($mountFormat)) {
				continue;
			}
			$source = trim((string)($mountFormat['source'] ?? ''));
			$format = trim((string)($mountFormat['format'] ?? ''));
			if ($source === '' || $source[0] !== '/' || !str_contains($format, '%snapshot%') || substr_count($source, self::USER_PLACEHOLDER) > 1) {
				continue;
			}
			// the placeholder needs to be a complete folder name
			if (str_contains($source, self::USER_PLACEHOLDER) && !preg_match('#(^|/)' . preg_quote(self::USER_PLACEHOLDER, '#') . '(/|$)#', $source)) {
				continue;
			}
			$result[] = ['source' => self::normalizePath($source), 'format' => $format];
		}
		return $result;
	}

	public function setDataDirectory(string $dataDirectory): void {
		$this->dataDirectory = $dataDirectory === '' ? null : self::normalizePath($dataDirectory);
	}

	private static function normalizePath(string $path): string {
		$path = rtrim((string)preg_replace('#/{2,}#', '/', $path), '/');
		return $path === '' ? '/' : $path;
	}

	/**
	 * List the snapshots of the Nextcloud data directory
	 *
	 * @return Traversable<Snapshot>
	 */
	public function listAllSnapshots(): Traversable {
		return $this->snapshotFormat->listSnapshots();
	}

	/**
	 * List the snapshots containing a distinct version of a file in the Nextcloud data directory
	 *
	 * @param string $file path relative to the data directory
	 * @return Snapshot[]
	 */
	public function listSnapshotsForFile(string $file): array {
		return $this->listSnapshotsForLocation(new SnapshotLocation($this->snapshotFormat, $file));
	}

	/**
	 * List the snapshots containing a distinct version of a file, oldest first
	 *
	 * @return Snapshot[]
	 */
	public function listSnapshotsForLocation(SnapshotLocation $location): array {
		$file = $location->relativePath;
		$lastMtime = 0;
		$allSnapshots = array_filter(iterator_to_array($location->format->listSnapshots(), false), function (Snapshot $snapshot) use ($file) {
			return $snapshot->hasFile($file);
		});

		usort($allSnapshots, function (Snapshot $a, Snapshot $b) {
			return $a->getSnapshotDate()->getTimestamp() <=> $b->getSnapshotDate()->getTimestamp();
		});
		return array_values(array_filter($allSnapshots, function (Snapshot $snapshot) use (&$lastMtime, $file) {
			$snapshotMtime = $snapshot->getMtime($file);
			if ($snapshotMtime > $lastMtime) {
				$lastMtime = $snapshotMtime;
				return true;
			}
			return false;
		}));
	}

	/**
	 * Get a snapshot of the Nextcloud data directory
	 */
	public function getSnapshot(string $id): ?Snapshot {
		return $this->snapshotFormat->getSnapshot($id);
	}

	/**
	 * Find where the snapshots for a file or folder on the local filesystem are stored
	 *
	 * The configured locations (the data directory and any additional mount formats) are tried,
	 * using the one with the longest matching source path.
	 *
	 * @param string $path absolute path on the local filesystem
	 */
	public function resolve(string $path): ?SnapshotLocation {
		$path = self::normalizePath($path);

		$candidates = $this->mountFormats;
		if ($this->dataDirectory !== null && $this->snapshotFormat->isValid()) {
			$candidates[] = ['source' => $this->dataDirectory, 'format' => $this->rawSnapshotFormat];
		}

		$best = null;
		foreach ($candidates as $candidate) {
			$match = self::matchSource($candidate['source'], $path);
			if ($match !== null && ($best === null || $match['length'] > $best['length'])) {
				$best = $match + ['format' => $candidate['format']];
			}
		}
		if ($best !== null) {
			$format = $best['user'] === null
				? $best['format']
				: str_replace(self::USER_PLACEHOLDER, SnapshotFormat::escapeGlob($best['user']), $best['format']);
			$snapshotFormat = new SnapshotFormat($format, $this->dateFormat);
			if ($snapshotFormat->isValid()) {
				return new SnapshotLocation($snapshotFormat, $best['relative']);
			}
		}

		return null;
	}

	/**
	 * Match a path against the source of a location, which can contain the user placeholder
	 *
	 * Returns the path relative to the source, the folder name matched by the placeholder
	 * and the length of the matched part of the path, or null if the path isn't inside the source.
	 *
	 * @return ?array{relative: string, user: ?string, length: int}
	 */
	private static function matchSource(string $source, string $path): ?array {
		if (!str_contains($source, self::USER_PLACEHOLDER)) {
			$relative = self::getRelativePath($source, $path);
			return $relative === null ? null : ['relative' => $relative, 'user' => null, 'length' => strlen($source)];
		}
		[$before, $after] = explode(self::USER_PLACEHOLDER, $source, 2);
		$regex = '#^' . preg_quote($before, '#') . '([^/]+)' . preg_quote($after, '#') . '(?:/(.*))?$#s';
		if (!preg_match($regex, $path, $matches) || $matches[1] === '.' || $matches[1] === '..') {
			return null;
		}
		$user = $matches[1];
		return ['relative' => $matches[2] ?? '', 'user' => $user, 'length' => strlen($source) - strlen(self::USER_PLACEHOLDER) + strlen($user)];
	}

	private static function getRelativePath(string $root, string $path): ?string {
		if ($root === '/') {
			return ltrim($path, '/');
		}
		if ($path === $root) {
			return '';
		}
		if (str_starts_with($path, $root . '/')) {
			return substr($path, strlen($root) + 1);
		}
		return null;
	}
}
