<?php

declare(strict_types=1);
/**
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\Files_Snapshots\Tests;

use OCA\Files_Snapshots\SnapshotManager;

/**
 * Snapshot manager for tests: snapshot directories created by the tests can't be separate filesystems like
 * real snapshots, so they are treated as such unless they contain a `.user-created` marker, which simulates
 * a directory created by a user through Nextcloud.
 */
class TestSnapshotManager extends SnapshotManager {
	public const USER_CREATED_MARKER = '.user-created';

	protected function isSeparateFilesystem(string $path, string $parent): bool {
		return !file_exists($path . '/' . self::USER_CREATED_MARKER);
	}

	public function isSeparateFilesystemOnDisk(string $path, string $parent): bool {
		return parent::isSeparateFilesystem($path, $parent);
	}

	public function isFilesystemRootOnDisk(string $dir, string $parent): bool {
		return parent::isFilesystemRoot($dir, $parent);
	}

	public static function containsSymlinkOnDisk(string $root, string $path): bool {
		return parent::containsSymlink($root, $path);
	}
}
