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

use OC\Files\Storage\Local;
use OCA\Files_Snapshots\Snapshot;
use OCA\Files_Snapshots\SnapshotManager;
use OCA\Files_Versions\Versions\IVersion;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;

class SnapshotVersionBackend implements IVersionBackend {
	public function __construct(
		private SnapshotManager $versionProvider,
	) {
	}

	public function useBackendForStorage(IStorage $storage): bool {
		return true;
	}

	public function getVersionsForFile(IUser $user, FileInfo $file): array {
		$snapshots = $this->versionProvider->listSnapshotsForFile($user->getUID() . '/' . $file->getInternalPath());

		return array_map(function (Snapshot $snapshot) use ($file, $user) {
			return new SnapshotVersion(
				$this,
				$snapshot,
				$file,
				$user
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
		$snapshot = null;
		$snapshots = $this->getVersionsForFile($user, $sourceFile);
		foreach ($snapshots as $fileSnapshot) {
			if ($fileSnapshot->getRevisionId() === $revision) {
				$snapshot = $fileSnapshot;
			}
		}
		if (!$snapshot) {
			throw new NotFoundException("No snapshot found for revision $revision");
		}

		return new SnapshotPreviewFile($sourceFile, function () use ($sourceFile, $revision, $user) {
			return $this->versionProvider->getSnapshot($revision)->readFile($user->getUID() . '/' . $sourceFile->getInternalPath());
		}, $snapshot);
	}

	public function getRevision(Node $node): int {
		return $node->getMTime();
	}
}
