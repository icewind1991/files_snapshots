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

use DateTime;

class Snapshot {
	private string $path;

	public function __construct(
		string $path,
		private readonly string $name,
		private readonly string $dateFormat,
	) {
		$this->path = rtrim($path, '/');
	}

	public function getPath(): string {
		return $this->path;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getFilePath($file): string {
		return $this->path . '/' . $file;
	}

	/**
	 * The path of a file in the snapshot, if it exists and is inside the snapshot
	 *
	 * A snapshot can contain symlinks, for example ones a user created before the snapshot was taken. Following one
	 * that points outside the snapshot would give access to other files on the server, the same way the local
	 * storage of Nextcloud doesn't follow those.
	 */
	private function resolveFile(string $file): ?string {
		$path = $this->getFilePath($file);
		$realPath = realpath($path);
		$realRoot = realpath($this->path);
		if ($realPath === false || $realRoot === false) {
			return null;
		}
		if ($realPath !== $realRoot && !str_starts_with($realPath, rtrim($realRoot, '/') . '/')) {
			return null;
		}
		return $path;
	}

	public function hasFile($file): bool {
		return $this->resolveFile((string)$file) !== null;
	}

	public function getMtime($file): int {
		$path = $this->resolveFile((string)$file);
		return $path === null ? 0 : (int)filemtime($path);
	}

	public function getSize($file): int {
		$path = $this->resolveFile((string)$file);
		return $path === null ? 0 : (int)filesize($path);
	}

	public function getSnapshotDate(): DateTime {
		$date = DateTime::createFromFormat($this->dateFormat, $this->getName());
		if (!$date) {
			$date = new DateTime();
			$mtime = $this->getMtime('');
			$date->setTimestamp($mtime);
		}
		return $date;
	}

	/**
	 * @param string $file
	 * @return resource|false
	 */
	public function readFile(string $file) {
		$path = $this->resolveFile($file);
		return $path === null ? false : fopen($path, 'r');
	}
}
