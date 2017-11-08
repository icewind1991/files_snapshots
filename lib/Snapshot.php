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


class Snapshot {
	/** @var string */
	private $path;

	/** @var string */
	private $name;

	/** @var string */
	private $dateFormat;

	/**
	 * Snapshot constructor.
	 *
	 * @param string $path
	 * @param string $name
	 * @param string $dateFormat
	 */
	public function __construct($path, $name, $dateFormat) {
		$this->path = rtrim($path, '/');
		$this->name = $name;
		$this->dateFormat = $dateFormat;
	}

	/**
	 * @return string
	 */
	public function getPath() {
		return $this->path;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	public function getFilePath($file) {
		return $this->path . '/' . $file;
	}

	public function hasFile($file) {
		return file_exists($this->getFilePath($file));
	}

	public function getMtime($file) {
		return filemtime($this->getFilePath($file));
	}

	public function getSize($file) {
		return filesize($this->getFilePath($file));
	}

	public function getSnapshotDate() {
		$info_file = realpath($this->path.'/../info.xml');
		if (is_readable($info_file) && function_exists('simplexml_load_string') && function_exists('libxml_disable_entity_loader')) {
			$dateFormat = "Y-m-d H:i:s";
			$xml = simplexml_load_file($info_file);
			$date = \DateTime::createFromFormat($dateFormat, $xml->date);
			return $date;
		}
		return \DateTime::createFromFormat('*' . $this->dateFormat . '*', $this->getName() . '*');
	}

	public function readFile($file) {
		return fopen($this->getFilePath($file), 'r');
	}
}
