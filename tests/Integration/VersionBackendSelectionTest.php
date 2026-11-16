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

namespace OCA\Files_Snapshots\Tests\Integration;

use OC\Files\Storage\Local;
use OCA\Files_Snapshots\Tests\TestSnapshotManager;
use OCA\Files_Snapshots\Versions\SnapshotVersionBackend;
use OCA\Files_Versions\Versions\IVersionBackend;
use OCA\Files_Versions\Versions\VersionManager;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use Test\TestCase;

require_once __DIR__ . '/../fixtures/groupfolders.php';

/**
 * Check which versions backend Nextcloud's version manager selects for each kind of storage,
 * using the backends registered in this app's info.xml
 *
 * @group DB
 */
class VersionBackendSelectionTest extends TestCase {
	private const GROUP_FOLDER_STORAGE = 'OCA\GroupFolders\Mount\GroupFolderStorage';
	private const UNENCRYPTED_GROUP_FOLDER_STORAGE = 'OCA\GroupFolders\Mount\GroupFolderNoEncryptionStorage';

	private string $baseDir;
	private SnapshotVersionBackend $snapshotBackend;
	private IVersionBackend $legacyBackend;
	private IVersionBackend $groupFolderBackend;

	protected function setUp(): void {
		parent::setUp();
		\OC_App::loadApp('files_versions');

		$this->baseDir = rtrim(\OC::$server->get(ITempManager::class)->getTemporaryFolder(), '/');
		mkdir($this->baseDir . '/withsnapshots/.zfs/snapshot/daily-2026-11-01', 0777, true);
		mkdir($this->baseDir . '/withoutsnapshots', 0777, true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $_key, $default = '') => $default);
		$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $_key, $default = []) => $default);
		$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $key, $default = false) => $key === 'autodetect' ? true : $default);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');
		$this->snapshotBackend = new SnapshotVersionBackend(new TestSnapshotManager($appConfig, $config));

		$this->legacyBackend = $this->createMock(IVersionBackend::class);
		$this->legacyBackend->method('useBackendForStorage')->willReturn(true);
		$this->groupFolderBackend = $this->createMock(IVersionBackend::class);
		$this->groupFolderBackend->method('useBackendForStorage')->willReturn(true);
	}

	/**
	 * Register the backends like files_versions does: the regular versions for all storages,
	 * the group folders backend, and the backends from this app's info.xml
	 *
	 * @param bool $snapshotsFirst whether this app registers its backends before group folders, the order apps are loaded in
	 */
	private function getVersionManager(bool $snapshotsFirst): VersionManager {
		$manager = new VersionManager($this->createMock(IEventDispatcher::class));
		$manager->registerBackend(IStorage::class, $this->legacyBackend);

		$registerGroupFolders = fn () => $manager->registerBackend(self::GROUP_FOLDER_STORAGE, $this->groupFolderBackend);
		$registerSnapshots = function () use ($manager): void {
			$types = $this->getRegisteredStorageTypes();
			foreach ($types as $type) {
				$manager->registerBackend($type, $this->snapshotBackend);
			}
		};

		if ($snapshotsFirst) {
			$registerSnapshots();
			$registerGroupFolders();
		} else {
			$registerGroupFolders();
			$registerSnapshots();
		}
		return $manager;
	}

	/**
	 * @return list<string>
	 */
	private function getRegisteredStorageTypes(): array {
		$appInfo = \OCP\Server::get(IAppManager::class)->getAppInfo('files_snapshots');
		$this->assertIsArray($appInfo);
		$types = [];
		foreach ($appInfo['versions'] as $backend) {
			foreach (isset($backend['@value']) ? [$backend] : $backend as $singleBackend) {
				$this->assertEquals(SnapshotVersionBackend::class, $singleBackend['@value']);
				$types[] = $singleBackend['@attributes']['for'];
			}
		}
		return $types;
	}

	/**
	 * A storage of the given class on local disk, like the real storages (or storage wrappers) it either
	 * is a local storage or wraps one, wrappers pass instanceOfStorage() on to the storage they wrap
	 */
	private function getStorage(string $class, string $localPath): IStorage {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $type) => is_a($class, ltrim($type, '\\'), true) || is_a(Local::class, ltrim($type, '\\'), true));
		$storage->method('getLocalFile')->willReturnCallback(fn (string $path) => rtrim($localPath . '/' . $path, '/'));
		return $storage;
	}

	public function testRegisteredTypes() {
		$this->assertEquals([
			'OC\Files\Storage\Home',
			'OC\Files\Storage\Local',
			self::UNENCRYPTED_GROUP_FOLDER_STORAGE,
		], $this->getRegisteredStorageTypes());
	}

	public static function selectionProvider(): array {
		$cases = [
			// [storage class, has snapshots, expected backend]
			'external storage with snapshots' => [Local::class, true, 'snapshots'],
			'external storage without snapshots' => [Local::class, false, 'legacy'],
			'home with snapshots' => ['OC\Files\Storage\Home', true, 'snapshots'],
			'home without snapshots' => ['OC\Files\Storage\Home', false, 'legacy'],
			'group folder with snapshots' => [self::UNENCRYPTED_GROUP_FOLDER_STORAGE, true, 'snapshots'],
			'group folder without snapshots' => [self::UNENCRYPTED_GROUP_FOLDER_STORAGE, false, 'groupfolders'],
			'encrypted group folder with snapshots' => [self::GROUP_FOLDER_STORAGE, true, 'groupfolders'],
		];
		$result = [];
		foreach ($cases as $name => $case) {
			$result["$name, snapshots registered first"] = [...$case, true];
			$result["$name, group folders registered first"] = [...$case, false];
		}
		return $result;
	}

	/**
	 * @dataProvider selectionProvider
	 */
	public function testSelectedBackend(string $class, bool $hasSnapshots, string $expected, bool $snapshotsFirst) {
		$storage = $this->getStorage($class, $this->baseDir . ($hasSnapshots ? '/withsnapshots' : '/withoutsnapshots'));
		$backend = $this->getVersionManager($snapshotsFirst)->getBackendForStorage($storage);

		$backends = [
			'snapshots' => $this->snapshotBackend,
			'legacy' => $this->legacyBackend,
			'groupfolders' => $this->groupFolderBackend,
		];
		$this->assertSame($backends[$expected], $backend, "expected the $expected backend");
	}
}
