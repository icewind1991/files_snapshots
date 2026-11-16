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
	private bool $autodetect = false;
	/** @var list<string> */
	private array $autodetectPatterns = self::DEFAULT_AUTODETECT_PATTERNS;
	/** @var array<string, ?array{root: string, pattern: string}> cache of directory => detected snapshot directory */
	private array $autodetectCache = [];

	/**
	 * Placeholder in the source of an additional location that matches a single folder name, such as a user id,
	 * the matched name is substituted into the snapshot format.
	 */
	public const USER_PLACEHOLDER = '%user%';

	/**
	 * Snapshot directories that are searched for in every parent directory of a file, relative to that directory
	 */
	public const DEFAULT_AUTODETECT_PATTERNS = [
		'.zfs/snapshot/%snapshot%', // zfs
		'.snapshots/%snapshot%/snapshot', // btrfs with snapper
		'.snapshot/%snapshot%', // NetApp and other NAS systems
		'.snap/%snapshot%', // CephFS
	];

	public function __construct(IAppConfig $appConfig, IConfig $config) {
		$this->setDataDirectory($config->getSystemValueString('datadirectory'));
		$this->setDateFormat($appConfig->getValueString('files_snapshots', 'date_format', 'Y-m-d_H:i:s'));
		$this->setSnapshotFormat($appConfig->getValueString('files_snapshots', 'snap_format'));
		$this->setMountFormats($appConfig->getValueArray('files_snapshots', 'mount_formats'));
		$this->setAutodetect($appConfig->getValueBool('files_snapshots', 'autodetect'));
		$this->setAutodetectPatterns($appConfig->getValueArray('files_snapshots', 'autodetect_patterns', self::DEFAULT_AUTODETECT_PATTERNS));
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

	public function setAutodetect(bool $autodetect): void {
		$this->autodetect = $autodetect;
	}

	public function setAutodetectPatterns(array $patterns): void {
		$this->autodetectPatterns = self::sanitizeAutodetectPatterns($patterns);
		$this->autodetectCache = [];
	}

	/**
	 * Patterns need to be relative paths with a non-empty directory before `%snapshot%`
	 *
	 * @return list<string>
	 */
	public static function sanitizeAutodetectPatterns(array $patterns): array {
		$result = [];
		foreach ($patterns as $pattern) {
			if (!is_string($pattern)) {
				continue;
			}
			$pattern = trim(trim($pattern), '/');
			$parts = explode('/', $pattern);
			$snapshotIndex = array_search('%snapshot%', $parts, true);
			if (
				$snapshotIndex === false || $snapshotIndex === 0
				|| in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true)
				|| substr_count($pattern, '%snapshot%') !== 1
			) {
				continue;
			}
			if (!in_array($pattern, $result, true)) {
				$result[] = $pattern;
			}
		}
		return $result;
	}

	public function setDataDirectory(string $dataDirectory): void {
		$this->dataDirectory = $dataDirectory === '' ? null : self::normalizePath($dataDirectory);
	}

	public function getDataDirectory(): ?string {
		return $this->dataDirectory;
	}

	/**
	 * @return list<array{source: string, format: string}>
	 */
	public function getMountFormats(): array {
		return $this->mountFormats;
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
	 * Configured locations (the data directory and any additional mount formats) are tried first,
	 * using the one with the longest matching source path. If none match and auto-detection is enabled
	 * the nearest parent directory containing a snapshot directory matching one of the patterns is used.
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

		if ($this->autodetect) {
			$detected = $this->detectSnapshotDirectory($path);
			if ($detected !== null) {
				$relative = self::getRelativePath($detected['root'], $path);
				if ($relative !== null) {
					return new SnapshotLocation($this->getDetectedFormat($detected['root'], $detected['pattern']), $relative, true);
				}
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

	/**
	 * The folders matched by the source of a location, for sources containing the user placeholder
	 *
	 * @return list<string>
	 */
	public static function expandSource(string $source): array {
		if (!str_contains($source, self::USER_PLACEHOLDER)) {
			return [$source];
		}
		$pattern = str_replace(SnapshotFormat::escapeGlob(self::USER_PLACEHOLDER), '*', SnapshotFormat::escapeGlob($source));
		$result = [];
		foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $dir) {
			if (self::matchSource($source, $dir) !== null) {
				$result[] = $dir;
			}
		}
		return $result;
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

	/**
	 * Snapshot format for an auto-detected snapshot directory, which only uses real snapshots
	 *
	 * Users can create directories with the same name as a snapshot directory, or entries inside a snapshot
	 * directory they can write to, through Nextcloud. Those would make arbitrary files show up as versions of other
	 * files, or hide the real snapshots. Real snapshots are separate filesystems (zfs mounts every snapshot, btrfs
	 * snapshots are subvolumes), so they are on a different device than the directory containing them, while
	 * directories created through Nextcloud are always on the same device as their parent. Every snapshot is checked.
	 * Symlinks are not followed, they could point to other filesystems.
	 * Locations configured by the admin are not checked.
	 */
	private function getDetectedFormat(string $root, string $pattern): SnapshotFormat {
		return SnapshotFormat::forPattern($root, $pattern, $this->dateFormat,
			fn (Snapshot $snapshot): bool => !self::containsSymlink($root, $snapshot->getPath())
				&& $this->isSeparateFilesystem($snapshot->getPath(), dirname($snapshot->getPath())));
	}

	/**
	 * Whether any part of a path below a directory is a symlink
	 */
	protected static function containsSymlink(string $root, string $path): bool {
		$relative = self::getRelativePath(self::normalizePath($root), self::normalizePath($path));
		if ($relative === null) {
			return true;
		}
		$current = self::normalizePath($root);
		foreach (explode('/', $relative) as $part) {
			if ($part === '') {
				continue;
			}
			$current = ($current === '/' ? '' : $current) . '/' . $part;
			if (is_link($current)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an auto-detected snapshot directory contains (at least one) real snapshot, see getDetectedFormat()
	 */
	private function containsSnapshotFilesystems(string $root, string $pattern): bool {
		foreach ($this->getDetectedFormat($root, $pattern)->listSnapshots() as $_snapshot) {
			return true;
		}
		return false;
	}

	/**
	 * Whether a path is on a different device than its parent directory
	 */
	protected function isSeparateFilesystem(string $path, string $parent): bool {
		// the trailing slash makes sure an automounted snapshot (zfs) is mounted before checking
		$pathStat = @stat(rtrim($path, '/') . '/');
		$parentStat = @stat($parent);
		if ($pathStat === false || $parentStat === false) {
			return false;
		}
		return $pathStat['dev'] !== $parentStat['dev'];
	}

	/**
	 * Whether a directory is on a different device than its parent, meaning it is the root of a mounted filesystem
	 */
	protected function isFilesystemRoot(string $dir, string $parent): bool {
		$dirStat = @stat($dir);
		$parentStat = @stat($parent);
		if ($dirStat === false || $parentStat === false) {
			return false;
		}
		return $dirStat['dev'] !== $parentStat['dev'];
	}

	/**
	 * Find the nearest parent directory of a path that contains a snapshot directory
	 *
	 * Snapshot directories are found by checking for the part of each pattern before `%snapshot%`,
	 * this also works for hidden directories such as zfs with `snapdir=hidden`.
	 *
	 * Only snapshot directories containing real snapshots are used, see getDetectedFormat().
	 *
	 * The search stops at the root of the filesystem containing the path (where the device changes), since snapshots
	 * of a parent filesystem don't contain the files of another filesystem mounted inside it, only the empty
	 * directory it is mounted on. This happens for nested zfs datasets and btrfs subvolumes.
	 *
	 * @return ?array{root: string, pattern: string}
	 */
	public function detectSnapshotDirectory(string $path): ?array {
		$path = self::normalizePath($path);
		$dir = is_dir($path) ? $path : dirname($path);
		$visited = [];
		while (true) {
			if (array_key_exists($dir, $this->autodetectCache)) {
				$found = $this->autodetectCache[$dir];
				break;
			}
			$visited[] = $dir;
			$found = null;
			foreach ($this->autodetectPatterns as $pattern) {
				$snapshotDir = explode('/%snapshot%', $pattern, 2)[0];
				if (is_dir(($dir === '/' ? '' : $dir) . '/' . $snapshotDir) && $this->containsSnapshotFilesystems($dir, $pattern)) {
					$found = ['root' => $dir, 'pattern' => $pattern];
					break 2;
				}
			}
			$parent = dirname($dir);
			if ($parent === $dir || $this->isFilesystemRoot($dir, $parent)) {
				break;
			}
			$dir = $parent;
		}
		foreach ($visited as $visitedDir) {
			$this->autodetectCache[$visitedDir] = $found;
		}
		return $found;
	}
}
