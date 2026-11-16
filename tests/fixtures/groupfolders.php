<?php

declare(strict_types=1);
/**
 * Stand-ins for the group folder storages when the group folders app is not installed,
 * with the same class hierarchy, which is what versions backend selection depends on.
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\GroupFolders\Mount;

use OC\Files\Storage\Wrapper\Quota;

if (!class_exists(GroupFolderStorage::class)) {
	class GroupFolderStorage extends Quota {
	}
}

if (!class_exists(GroupFolderNoEncryptionStorage::class)) {
	class GroupFolderNoEncryptionStorage extends GroupFolderStorage {
	}
}
