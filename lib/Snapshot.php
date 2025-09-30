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
	/** @var string */
	private $path;

	/**
	 * Snapshot constructor.
	 *
	 * @param string $path
	 * @param string $name
	 * @param string $dateFormat
	 */
	public function __construct(
		string $path,
		private string $name,
		private string $dateFormat,
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

	public function hasFile($file): bool {
		return file_exists($this->getFilePath($file));
	}

	public function getMtime($file): int {
		return (int)filemtime($this->getFilePath($file));
	}

	public function getSize($file): int {
		return (int)filesize($this->getFilePath($file));
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
		return fopen($this->getFilePath($file), 'r');
	}
}
