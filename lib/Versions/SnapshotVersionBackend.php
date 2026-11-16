<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
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

use OC\Files\Node\Node;
use OC\Files\Storage\Local;
use OCA\Files_Snapshots\Snapshot;
use OCA\Files_Snapshots\SnapshotLocation;
use OCA\Files_Snapshots\SnapshotManager;
use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

class SnapshotVersionBackend implements IVersionBackend {
	/**
	 * Group folders without encryption, other group folders can't use snapshots since their files are encrypted on disk.
	 *
	 * This backend is registered for the unencrypted group folder storage in info.xml, which is more specific than
	 * the storage type group folders registers its own versions backend for, so snapshots are preferred when available.
	 */
	private const GROUP_FOLDER_STORAGE = 'OCA\GroupFolders\Mount\GroupFolderStorage';
	private const UNENCRYPTED_GROUP_FOLDER_STORAGE = 'OCA\GroupFolders\Mount\GroupFolderNoEncryptionStorage';

	public function __construct(
		private readonly SnapshotManager $versionProvider,
	) {
	}

	/**
	 * Use snapshots for local storages (home folders, external storage, group folders, shares) that have snapshots,
	 * other storages keep using their regular versions.
	 */
	public function useBackendForStorage(IStorage $storage): bool {
		if ($storage->instanceOfStorage(self::GROUP_FOLDER_STORAGE) && !$storage->instanceOfStorage(self::UNENCRYPTED_GROUP_FOLDER_STORAGE)) {
			return false;
		}
		$root = $this->getLocalPath($storage, '');
		return $root !== null && $this->versionProvider->resolve($root) !== null;
	}

	/**
	 * Get the path on the local filesystem for a file, without copying files from non-local storages
	 */
	private function getLocalPath(IStorage $storage, string $internalPath): ?string {
		if (!$storage->instanceOfStorage(Local::class)) {
			return null;
		}
		$path = $storage->getLocalFile($internalPath);
		return is_string($path) ? $path : null;
	}

	private function getLocation(FileInfo $file): ?SnapshotLocation {
		$storage = $file->getStorage();
		$path = $this->getLocalPath($storage, $file->getInternalPath());
		return $path === null ? null : $this->versionProvider->resolve($path);
	}

	/**
	 * @return SnapshotVersion[]
	 */
	public function getVersionsForFile(IUser $user, FileInfo $file): array {
		$location = $this->getLocation($file);
		if ($location === null) {
			return [];
		}
		$snapshots = $this->versionProvider->listSnapshotsForLocation($location);

		return array_map(function (Snapshot $snapshot) use ($file, $user, $location) {
			return new SnapshotVersion(
				$this,
				$snapshot,
				$file,
				$user,
				$location->relativePath,
			);
		}, $snapshots);
	}

	public function createVersion(IUser $user, FileInfo $file) {
		// noop
	}

	public function rollback(IVersion $version) {
		$source = $version->getSourceFile();
		$storage = $source->getStorage();

		if ($version instanceof SnapshotVersion) {
			$versionStorage = new Local(['datadir' => $version->getSnapshot()->getPath()]);
			return $storage->copyFromStorage($versionStorage, $version->getVersionPath(), $source->getInternalPath());
		} else {
			return false;
		}
	}

	public function read(IVersion $version) {
		if ($version instanceof SnapshotVersion) {
			return $version->getSnapshot()->readFile($version->getVersionPath());
		} else {
			return false;
		}
	}

	public function getVersionFile(IUser $user, FileInfo $sourceFile, $revision): File {
		$version = null;
		foreach ($this->getVersionsForFile($user, $sourceFile) as $fileVersion) {
			if ((string)$fileVersion->getRevisionId() === (string)$revision) {
				$version = $fileVersion;
			}
		}
		if (!$version) {
			throw new NotFoundException("No snapshot found for revision $revision");
		}

		return new SnapshotPreviewFile($sourceFile, function () use ($version) {
			return $version->getSnapshot()->readFile($version->getVersionPath());
		}, $version);
	}

	public function getRevision(Node $node): int {
		return $node->getMTime();
	}
}
