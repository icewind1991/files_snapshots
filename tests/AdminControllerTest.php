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

namespace OCA\Files_Snapshots\Tests;

use OCA\Files_Snapshots\Controller\AdminController;
use OCA\Files_Snapshots\ExternalStorages;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ITempManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AdminControllerTest extends TestCase {
	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();
		$this->baseDir = rtrim(\OC::$server->get(ITempManager::class)->getTemporaryFolder(), '/');
		$base = $this->baseDir;

		// data directory, snapshots configured manually
		mkdir("$base/data/admin/files", 0777, true);
		foreach (['snap-2026-11-01', 'snap-2026-11-02'] as $name) {
			mkdir("$base/datasnaps/$name/data/admin/files", 0777, true);
		}
		mkdir("$base/datasnaps/snap-2026-10-31/data", 0777, true); // older snapshot without any user files

		// external storage that is a zfs dataset
		mkdir("$base/ext/Photos/.zfs/snapshot/daily-2026-11-01/2026", 0777, true);
		mkdir("$base/ext/Photos/.zfs/snapshot/daily-2026-11-02/2026", 0777, true);
		mkdir("$base/ext/Photos/2026", 0777, true);

		// external storage that is a sub folder of a zfs dataset
		mkdir("$base/pool/.zfs/snapshot/daily-2026-11-01/Music", 0777, true);
		mkdir("$base/pool/.zfs/snapshot/daily-2026-11-02", 0777, true); // created before the Music folder
		mkdir("$base/pool/Music", 0777, true);

		// external storage without snapshots
		mkdir("$base/ext/Plain", 0777, true);

		// additional location
		mkdir("$base/backup/home/b1/notes", 0777, true);
		mkdir("$base/home/notes", 0777, true);
	}

	private function getController(?IAppConfig $appConfig = null): AdminController {
		$base = $this->baseDir;
		if ($appConfig === null) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $_key, $default = '') => $default);
			$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $_key, $default = []) => $default);
			$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $_key, $default = false) => $default);
		}
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturnCallback(fn ($key, $default = '') => $key === 'datadirectory' ? "$base/data" : $default);
		$externalStorages = $this->createMock(ExternalStorages::class);
		$externalStorages->method('getLocalStorages')->willReturn([
			['mountPoint' => '/Music', 'datadir' => "$base/pool/Music"],
			['mountPoint' => '/Photos', 'datadir' => "$base/ext/Photos/"],
			['mountPoint' => '/Plain', 'datadir' => "$base/ext/Plain"],
		]);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return new AdminController('files_snapshots', $this->createMock(IRequest::class), $appConfig, new TestSnapshotManager($appConfig, $config), $externalStorages, $l10n);
	}

	public function testListLocations() {
		$base = $this->baseDir;
		$locations = $this->getController()->listLocations(
			"$base/datasnaps/%snapshot%/data",
			'*-Y-m-d',
			[['source' => "$base/home", 'format' => "$base/backup/home/%snapshot%"]],
			true,
			".zfs/snapshot/%snapshot%\n.snapshots/%snapshot%/snapshot",
		);

		$this->assertEquals([
			['type' => 'datadir', 'label' => 'Data directory', 'source' => "$base/data", 'found' => true,
				'format' => "$base/datasnaps/%snapshot%/data", 'relativePath' => '', 'autodetected' => false, 'snapshotCount' => 3],
			['type' => 'external', 'label' => '/Music', 'source' => "$base/pool/Music", 'found' => true,
				'format' => "$base/pool/.zfs/snapshot/%snapshot%/", 'relativePath' => 'Music', 'autodetected' => true, 'snapshotCount' => 2],
			['type' => 'external', 'label' => '/Photos', 'source' => "$base/ext/Photos/", 'found' => true,
				'format' => "$base/ext/Photos/.zfs/snapshot/%snapshot%/", 'relativePath' => '', 'autodetected' => true, 'snapshotCount' => 2],
			['type' => 'external', 'label' => '/Plain', 'source' => "$base/ext/Plain", 'found' => false],
			['type' => 'additional', 'label' => 'Additional location', 'source' => "$base/home", 'found' => true,
				'format' => "$base/backup/home/%snapshot%", 'relativePath' => '', 'autodetected' => false, 'snapshotCount' => 1],
		], $locations);
	}

	public function testListLocationsUserPlaceholder() {
		$base = $this->baseDir;
		mkdir("$base/users/alice/files", 0777, true);
		mkdir("$base/users/bob/files", 0777, true);
		mkdir("$base/userbackup/alice/daily.0/files", 0777, true);

		$locations = $this->getController()->listLocations('', 'Y-m-d', [
			['source' => "$base/users/%user%/files", 'format' => "$base/userbackup/%user%/%snapshot%/files"],
			['source' => "$base/nobody/%user%", 'format' => "$base/userbackup/%user%/%snapshot%"],
		], false, '');
		$additional = array_values(array_filter($locations, fn ($location) => $location['type'] === 'additional'));
		$this->assertEquals([
			['type' => 'additional', 'label' => 'Additional location', 'source' => "$base/users/alice/files", 'found' => true,
				'format' => "$base/userbackup/alice/%snapshot%/files", 'relativePath' => '', 'autodetected' => false, 'snapshotCount' => 1],
			['type' => 'additional', 'label' => 'Additional location', 'source' => "$base/users/bob/files", 'found' => true,
				'format' => "$base/userbackup/bob/%snapshot%/files", 'relativePath' => '', 'autodetected' => false, 'snapshotCount' => 0],
			['type' => 'additional', 'label' => 'Additional location', 'source' => "$base/nobody/%user%", 'found' => false],
		], $additional);
	}

	public function testListLocationsWithoutAutodetect() {
		$base = $this->baseDir;
		$locations = $this->getController()->listLocations('', 'Y-m-d', [], false, '.zfs/snapshot/%snapshot%');
		$this->assertCount(4, $locations);
		foreach ($locations as $location) {
			$this->assertFalse($location['found'], $location['source']);
		}
	}

	public function testSettings() {
		$base = $this->baseDir;
		$controller = $this->getController();
		$this->assertEquals(['daily-2026-11-01', 'daily-2026-11-02'], array_keys($controller->testSettings("$base/pool/.zfs/snapshot/%snapshot%/", '*-Y-m-d')));
		$this->assertEquals([], $controller->testSettings("$base/pool/no-placeholder", '*-Y-m-d'));
	}

	public function testTestPathOnlyListsRealSnapshots() {
		$base = $this->baseDir;
		// a fake snapshot created by a user next to the real ones
		mkdir("$base/ext/Photos/.zfs/snapshot/fake-2026-11-03/2026", 0777, true);
		touch("$base/ext/Photos/.zfs/snapshot/fake-2026-11-03/" . TestSnapshotManager::USER_CREATED_MARKER);

		$result = $this->getController()->testPath("$base/ext/Photos", '', '*-Y-m-d', [], true, '.zfs/snapshot/%snapshot%');
		$this->assertTrue($result['found']);
		$this->assertEquals(['daily-2026-11-01', 'daily-2026-11-02'], array_keys($result['snapshots']));
	}

	public function testTestPath() {
		$base = $this->baseDir;
		file_put_contents("$base/ext/Photos/2026/a.jpg", 'current');
		file_put_contents("$base/ext/Photos/.zfs/snapshot/daily-2026-11-01/2026/a.jpg", 'old');
		touch("$base/ext/Photos/.zfs/snapshot/daily-2026-11-01/2026/a.jpg", 1000);
		file_put_contents("$base/ext/Photos/.zfs/snapshot/daily-2026-11-02/2026/a.jpg", 'old');
		touch("$base/ext/Photos/.zfs/snapshot/daily-2026-11-02/2026/a.jpg", 1000);

		$result = $this->getController()->testPath("$base/ext/Photos/2026/a.jpg", '', '*-Y-m-d', [], true, '.zfs/snapshot/%snapshot%');
		$this->assertEquals([
			'found' => true,
			'relativePath' => '2026/a.jpg',
			'example' => "$base/ext/Photos/.zfs/snapshot/daily-2026-11-01/2026/a.jpg",
			'snapshotCount' => 2,
			'snapshots' => ['daily-2026-11-01' => '2026-11-01 00:00:00', 'daily-2026-11-02' => '2026-11-02 00:00:00'],
			// unchanged in the second snapshot
			'versionCount' => 1,
		], $this->fixTimes($result));

		// uses the posted settings, not the saved ones
		$this->assertEquals(['found' => false], $this->getController()->testPath("$base/ext/Photos/2026/a.jpg", '', 'Y-m-d', [], false, '.zfs/snapshot/%snapshot%'));
		$this->assertEquals(['found' => false], $this->getController()->testPath("$base/ext/Photos/2026/a.jpg", '', 'Y-m-d', [], true, ''));

		$result = $this->getController()->testPath("$base/home/notes", '', 'Y-m-d', [['source' => "$base/home", 'format' => "$base/backup/home/%snapshot%"]]);
		$this->assertTrue($result['found']);
		$this->assertEquals('notes', $result['relativePath']);
	}

	/**
	 * Dates from the name don't include seconds, ignore the time part when comparing
	 */
	private function fixTimes(array $result): array {
		if (isset($result['snapshots'])) {
			$result['snapshots'] = array_map(fn (string $date) => substr($date, 0, 10) . ' 00:00:00', $result['snapshots']);
		}
		return $result;
	}

	public function testSave() {
		$appConfig = $this->createMock(IAppConfig::class);
		$saved = [];
		$appConfig->method('setValueString')->willReturnCallback(function ($app, $key, $value) use (&$saved) {
			$saved[$app][$key] = $value;
			return true;
		});
		$appConfig->method('setValueArray')->willReturnCallback(function ($app, $key, $value) use (&$saved) {
			$saved[$app][$key] = $value;
			return true;
		});
		$appConfig->method('setValueBool')->willReturnCallback(function ($app, $key, $value) use (&$saved) {
			$saved[$app][$key] = $value;
			return true;
		});

		$result = $this->getController($appConfig)->save(
			'/data/.zfs/snapshot/%snapshot%',
			'*-Y-m-d',
			[
				['source' => '/mnt/a/', 'format' => '/mnt/a/.zfs/snapshot/%snapshot%'],
				['source' => 'relative', 'format' => '/x/%snapshot%'],
				['source' => '/mnt/b', 'format' => '/no-placeholder'],
				'invalid',
			],
			true,
			" .zfs/snapshot/%snapshot% \n\n%snapshot%\n.snapshots/%snapshot%/snapshot\r\n../up/%snapshot%",
		);

		$expected = [
			'snap_format' => '/data/.zfs/snapshot/%snapshot%',
			'date_format' => '*-Y-m-d',
			'mount_formats' => [['source' => '/mnt/a', 'format' => '/mnt/a/.zfs/snapshot/%snapshot%']],
			'autodetect' => true,
			'autodetect_patterns' => ['.zfs/snapshot/%snapshot%', '.snapshots/%snapshot%/snapshot'],
		];
		$this->assertEquals(['files_snapshots' => $expected], $saved);
		$this->assertEquals([
			'snapshotFormat' => $expected['snap_format'],
			'dateFormat' => $expected['date_format'],
			'mountFormats' => $expected['mount_formats'],
			'autodetect' => true,
			'autodetectPatterns' => $expected['autodetect_patterns'],
		], $result);
	}

	public function testExternalStorages() {
		$service = new FakeGlobalStoragesService([
			new FakeStorageConfig('/b', 'local', ['datadir' => '/mnt/b']),
			new FakeStorageConfig('/smb', 'smb', ['host' => 'nas']),
			new FakeStorageConfig('/A', 'local', ['datadir' => '/mnt/a']),
			new FakeStorageConfig('/broken', 'local', []),
		]);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);

		$storages = new ExternalStorages($appManager, $container, $this->createMock(LoggerInterface::class));
		$this->assertEquals([
			['mountPoint' => '/A', 'datadir' => '/mnt/a'],
			['mountPoint' => '/b', 'datadir' => '/mnt/b'],
		], $storages->getLocalStorages());

		$disabled = $this->createMock(IAppManager::class);
		$disabled->method('isEnabledForAnyone')->willReturn(false);
		$this->assertEquals([], (new ExternalStorages($disabled, $container, $this->createMock(LoggerInterface::class)))->getLocalStorages());
	}
}

class FakeBackend {
	public function __construct(
		private string $identifier,
	) {
	}

	public function getIdentifier(): string {
		return $this->identifier;
	}
}

class FakeStorageConfig {
	public function __construct(
		private string $mountPoint,
		private string $backend,
		private array $options,
	) {
	}

	public function getMountPoint(): string {
		return $this->mountPoint;
	}

	public function getBackend(): FakeBackend {
		return new FakeBackend($this->backend);
	}

	public function getBackendOption(string $key): mixed {
		return $this->options[$key] ?? null;
	}
}

class FakeGlobalStoragesService {
	public function __construct(
		private array $storages,
	) {
	}

	public function getAllStorages(): array {
		return $this->storages;
	}
}
