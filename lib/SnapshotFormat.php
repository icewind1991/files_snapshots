<?php

declare(strict_types=1);
/**
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

use Closure;
use Generator;

/**
 * A single snapshot location, described by a format such as `/tank/.zfs/snapshot/%snapshot%/data`.
 *
 * The part before `%snapshot%` may contain glob patterns to search multiple directories.
 */
class SnapshotFormat {
	private ?string $prefix = null;
	private ?string $postfix = null;

	public function __construct(
		string $format,
		private readonly string $dateFormat,
		/** @var ?Closure(Snapshot): bool only use snapshots this returns true for */
		private readonly ?Closure $filter = null,
	) {
		// allow the format to end in `%snapshot%` without a trailing slash
		if (str_ends_with($format, '/%snapshot%')) {
			$format .= '/';
		}
		if (str_contains($format, '/%snapshot%/')) {
			[$this->prefix, $this->postfix] = explode('/%snapshot%/', $format, 2);
		}
	}

	/**
	 * Snapshot format for an auto-detected snapshot directory
	 *
	 * @param string $root the directory containing the snapshot directory
	 * @param string $pattern the snapshot directory relative to $root, such as `.zfs/snapshot/%snapshot%`
	 * @param ?Closure(Snapshot): bool $filter only use snapshots this returns true for
	 */
	public static function forPattern(string $root, string $pattern, string $dateFormat, ?Closure $filter = null): self {
		[$snapshotDir, $postfix] = array_pad(explode('/%snapshot%', $pattern, 2), 2, '');
		$root = rtrim($root, '/');
		return new self(self::escapeGlob($root . '/' . $snapshotDir) . '/%snapshot%/' . ltrim($postfix, '/'), $dateFormat, $filter);
	}

	public static function escapeGlob(string $path): string {
		return addcslashes($path, '\\*?[');
	}

	public function isValid(): bool {
		return $this->prefix !== null;
	}

	/**
	 * @return Generator<Snapshot>
	 */
	public function listSnapshots(): Generator {
		if ($this->prefix === null) {
			return;
		}
		foreach (glob($this->prefix, GLOB_ONLYDIR) ?: [] as $dir) {
			foreach (scandir($dir) ?: [] as $file) {
				$path = $dir . '/' . $file;
				if ($file[0] !== '.' && is_dir($path) && is_dir($path . '/' . $this->postfix)) {
					$snapshot = new Snapshot($path . '/' . $this->postfix, $file, $this->dateFormat);
					if ($this->filter === null || ($this->filter)($snapshot)) {
						yield $snapshot;
					}
				}
			}
		}
	}

	public function getSnapshot(string $id): ?Snapshot {
		if ($this->prefix === null || $id === '' || $id[0] === '.' || str_contains($id, '/')) {
			return null;
		}
		foreach (glob($this->prefix, GLOB_ONLYDIR) ?: [] as $dir) {
			$path = $dir . '/' . $id . '/' . $this->postfix;
			if (is_dir($path)) {
				$snapshot = new Snapshot($path, $id, $this->dateFormat);
				return $this->filter === null || ($this->filter)($snapshot) ? $snapshot : null;
			}
		}
		return null;
	}
}
