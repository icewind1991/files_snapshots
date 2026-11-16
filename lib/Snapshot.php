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
		/** the directory named after the snapshot, which can differ from $path when snapshots contain a sub folder */
		private readonly ?string $snapshotDir = null,
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
			$date = $this->getSnapperDate();
		}
		if (!$date) {
			$date = self::detectDate($this->getName());
		}
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

	/**
	 * Find a date in common formats in a snapshot name, such as `zfs-auto-snap_daily-2026-11-15-1350`,
	 * `auto-2026-10-15_23-10`, `GMT+01_2024-07-26_0755`, `autosnap_2021-02-21_19:16:36_daily` or `20260812-164508`
	 */
	public static function detectDate(string $name): ?DateTime {
		$regex = '/(?<!\d)(\d{4})-?(\d{2})-?(\d{2})(?:[T _.-]?(\d{2})[:.-]?(\d{2})(?:[:.-]?(\d{2}))?)?(?!\d)/';
		if (!preg_match_all($regex, $name, $matches, PREG_SET_ORDER)) {
			return null;
		}
		foreach ($matches as $match) {
			[$year, $month, $day] = [(int)$match[1], (int)$match[2], (int)$match[3]];
			$hour = (int)($match[4] ?? 0);
			$minute = (int)($match[5] ?? 0);
			$second = (int)($match[6] ?? 0);
			if ($year < 1970 || !checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
				continue;
			}
			$date = new DateTime();
			$date->setDate($year, $month, $day);
			$date->setTime($hour, $minute, $second);
			return $date;
		}
		return null;
	}

	/**
	 * snapper names snapshots by number and stores the date in an `info.xml` next to the snapshot
	 */
	private function getSnapperDate(): ?DateTime {
		if ($this->snapshotDir === null) {
			return null;
		}
		$info = $this->snapshotDir . '/info.xml';
		if (!is_file($info) || filesize($info) > 65536) {
			return null;
		}
		$content = (string)file_get_contents($info);
		if (!preg_match('#<date>\s*([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})\s*</date>#', $content, $matches)) {
			return null;
		}
		// snapper stores dates in UTC
		$date = DateTime::createFromFormat('Y-m-d H:i:s', $matches[1], new \DateTimeZone('UTC'));
		return $date ?: null;
	}
}
