<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Robin Appelman <robin@icewind.nl>
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

namespace OCA\Files_Snapshots\Versions;

use OCA\Files_Snapshots\Snapshot;
use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCP\Files\FileInfo;
use OCP\IUser;

class SnapshotVersion implements IVersion {
	private $backend;
	private $snapshot;
	private $sourceFile;
	private $user;

	public function __construct(SnapshotVersionBackend $backend, Snapshot $snapshot, FileInfo $sourceFile, IUser $user) {
		$this->backend = $backend;
		$this->snapshot = $snapshot;
		$this->sourceFile = $sourceFile;
		$this->user = $user;
	}

	public function getBackend(): IVersionBackend {
		return $this->backend;
	}

	public function getSourceFile(): FileInfo {
		return $this->sourceFile;
	}

	public function getRevisionId() {
		return $this->snapshot->getName();
	}

	public function getTimestamp(): int {
		return $this->snapshot->getMtime($this->getVersionPath());
	}

	public function getSize(): int {
		return $this->snapshot->getSize($this->getVersionPath());
	}

	public function getSourceFileName(): string {
		return $this->sourceFile->getName();
	}

	public function getMimeType(): string {
		return $this->sourceFile->getMimetype();
	}

	public function getVersionPath(): string {
		return $this->user->getUID() . '/' . $this->sourceFile->getInternalPath();
	}

	public function getUser(): IUser {
		return $this->user;
	}

	public function getSnapshot(): Snapshot {
		return $this->snapshot;
	}
}
