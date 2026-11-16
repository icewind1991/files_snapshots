<?php

declare(strict_types=1);
/**
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

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * List the "Local" external storages configured by the admin, without a hard dependency on files_external
 */
class ExternalStorages {
	private const SERVICE = 'OCA\Files_External\Service\GlobalStoragesService';

	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return list<array{mountPoint: string, datadir: string}> sorted by mount point
	 */
	public function getLocalStorages(): array {
		if (!$this->appManager->isEnabledForAnyone('files_external')) {
			return [];
		}
		$result = [];
		try {
			// files_external isn't necessarily loaded yet in this request
			$this->appManager->loadApp('files_external');
			$service = $this->container->get(self::SERVICE);
			foreach ($service->getAllStorages() as $storage) {
				if ($storage->getBackend()->getIdentifier() !== 'local') {
					continue;
				}
				$datadir = $storage->getBackendOption('datadir');
				if (is_string($datadir) && $datadir !== '') {
					$result[] = ['mountPoint' => (string)$storage->getMountPoint(), 'datadir' => $datadir];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to list external storages', ['exception' => $e, 'app' => 'files_snapshots']);
			return [];
		}
		usort($result, fn (array $a, array $b) => strnatcasecmp($a['mountPoint'], $b['mountPoint']));
		return $result;
	}
}
