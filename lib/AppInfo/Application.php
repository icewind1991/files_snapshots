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

namespace OCA\Files_Snapshots\AppInfo;

use OCA\Files_Snapshots\SnapshotManager;
use OCP\AppFramework\App;
use OCP\AppFramework\IAppContainer;

class Application extends App {
	public function __construct(array $urlParams = array()) {
		parent::__construct('files_snapshots', $urlParams);

		$container = $this->getContainer();

		$container->registerService(SnapshotManager::class, function (IAppContainer $appContainer) {
			$config = $appContainer->getServer()->getConfig();
			return new SnapshotManager(
				$config->getAppValue('files_snapshots', 'snap_format'),
				$config->getAppValue('files_snapshots', 'date_format', 'Y-m-d_H:i:s'),
                $config->getAppValue('files_snapshots', 'user_format', '')
			);
		});
	}
}
