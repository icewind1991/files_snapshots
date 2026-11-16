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

use OC\Files\Storage\Local;
use OC\Files\Storage\Temporary;
use OC\Files\Storage\Wrapper\Jail;
use OCA\Files_Snapshots\SnapshotManager;
use OCA\Files_Snapshots\Versions\SnapshotVersion;
use OCA\Files_Snapshots\Versions\SnapshotVersionBackend;
use OCP\Files\FileInfo;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUser;
use Test\TestCase;

/**
 * @group DB
 */
class SnapshotVersionBackendTest extends TestCase {
	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();

		$this->baseDir = \OC::$server->get(ITempManager::class)->getTemporaryFolder();

		// an external storage that is a zfs dataset
		$snapshots = [
			'zfs-auto-snap_daily-2026-11-13-1325' => 'first',
			'zfs-auto-snap_daily-2026-11-14-1325' => 'second',
			'zfs-auto-snap_daily-2026-11-15-1325' => 'second',
		];
		$mtime = 1000;
		foreach ($snapshots as $name => $content) {
			$dir = $this->baseDir . "/Michele/.zfs/snapshot/$name/Documents";
			mkdir($dir, 0777, true);
			file_put_contents("$dir/notes.txt", $content);
			touch("$dir/notes.txt", $content === 'first' ? $mtime : $mtime + 10);
		}
		mkdir($this->baseDir . '/Michele/Documents', 0777, true);
		file_put_contents($this->baseDir . '/Michele/Documents/notes.txt', 'current');

		// an external storage without snapshots
		mkdir($this->baseDir . '/Plain', 0777, true);
		file_put_contents($this->baseDir . '/Plain/notes.txt', 'plain');
	}

	/**
	 * @param bool $withSnapshots configure the location of the snapshots of the "Michele" storage
	 */
	private function getBackend(bool $withSnapshots, array $mountFormats = []): SnapshotVersionBackend {
		if ($withSnapshots) {
			$mountFormats[] = ['source' => $this->baseDir . '/Michele', 'format' => $this->baseDir . '/Michele/.zfs/snapshot/%snapshot%'];
		}
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn ($_app, $key, $default = '') => match ($key) {
				'date_format' => '*-*-*-Y-m-d-Hi',
				default => $default,
			});
		$appConfig->method('getValueArray')
			->willReturnCallback(fn ($_app, $key, $default = []) => $key === 'mount_formats' ? $mountFormats : $default);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('/nonexistent/data');
		return new SnapshotVersionBackend(new SnapshotManager($appConfig, $config));
	}

	private function getFile(IStorage $storage, string $internalPath): FileInfo {
		$file = $this->createMock(FileInfo::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn($internalPath);
		$file->method('getName')->willReturn(basename($internalPath));
		$file->method('getMimetype')->willReturn('text/plain');
		return $file;
	}

	public function testUseBackendForStorage() {
		$michele = new Local(['datadir' => $this->baseDir . '/Michele']);
		$plain = new Local(['datadir' => $this->baseDir . '/Plain']);

		$this->assertFalse($this->getBackend(false)->useBackendForStorage($michele));
		$this->assertTrue($this->getBackend(true)->useBackendForStorage($michele));
		$this->assertFalse($this->getBackend(true)->useBackendForStorage($plain));

		$this->assertTrue($this->getBackend(false, [
			['source' => $this->baseDir . '/Plain', 'format' => $this->baseDir . '/Michele/.zfs/snapshot/%snapshot%'],
		])->useBackendForStorage($plain));

		// wrapped storages are unwrapped
		$jail = new Jail(['storage' => $michele, 'root' => 'Documents']);
		$this->assertTrue($this->getBackend(true)->useBackendForStorage($jail));
	}

	public function testUseBackendForNonLocalStorage() {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		$storage->expects($this->never())->method('getLocalFile');
		$this->assertFalse($this->getBackend(true)->useBackendForStorage($storage));
	}

	/**
	 * @param string[] $classes
	 */
	private function getMockStorage(array $classes, string $localPath): IStorage {
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $class) => in_array(ltrim($class, '\\'), $classes, true));
		$storage->method('getLocalFile')->willReturnCallback(fn (string $path) => rtrim($localPath . '/' . $path, '/'));
		return $storage;
	}

	public function testHomeStorageWithoutSnapshotsUsesRegularVersions() {
		$home = $this->getMockStorage([Local::class, \OCP\Files\IHomeStorage::class, 'OC\Files\Storage\Home'], $this->baseDir . '/Plain');
		$this->assertFalse($this->getBackend(true)->useBackendForStorage($home));

		$home = $this->getMockStorage([Local::class, \OCP\Files\IHomeStorage::class, 'OC\Files\Storage\Home'], $this->baseDir . '/Michele');
		$this->assertTrue($this->getBackend(true)->useBackendForStorage($home));
	}

	public function testVersionsForExternalStorage() {
		$backend = $this->getBackend(true);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('michele');

		$storage = new Local(['datadir' => $this->baseDir . '/Michele']);
		$file = $this->getFile($storage, 'Documents/notes.txt');

		/** @var SnapshotVersion[] $versions */
		$versions = $backend->getVersionsForFile($user, $file);
		$this->assertCount(2, $versions);
		$this->assertEquals('zfs-auto-snap_daily-2026-11-13-1325', $versions[0]->getRevisionId());
		$this->assertEquals('zfs-auto-snap_daily-2026-11-14-1325', $versions[1]->getRevisionId());
		$this->assertEquals('Documents/notes.txt', $versions[0]->getVersionPath());
		$this->assertEquals(1000, $versions[0]->getTimestamp());
		$this->assertEquals(5, $versions[0]->getSize());
		$this->assertEquals('first', stream_get_contents($backend->read($versions[0])));

		$versionFile = $backend->getVersionFile($user, $file, 'zfs-auto-snap_daily-2026-11-13-1325');
		$this->assertEquals('first', $versionFile->getContent());

		$this->assertTrue($backend->rollback($versions[0]));
		$this->assertEquals('first', file_get_contents($this->baseDir . '/Michele/Documents/notes.txt'));
	}

	public function testVersionsThroughJail() {
		$backend = $this->getBackend(true);
		$user = $this->createMock(IUser::class);

		$jail = new Jail(['storage' => new Local(['datadir' => $this->baseDir . '/Michele']), 'root' => 'Documents']);
		$versions = $backend->getVersionsForFile($user, $this->getFile($jail, 'notes.txt'));
		$this->assertCount(2, $versions);
		$this->assertEquals('Documents/notes.txt', $versions[0]->getVersionPath());
	}

	public function testNoVersionsWithoutSnapshots() {
		$backend = $this->getBackend(true);
		$user = $this->createMock(IUser::class);

		$storage = new Local(['datadir' => $this->baseDir . '/Plain']);
		$this->assertEquals([], $backend->getVersionsForFile($user, $this->getFile($storage, 'notes.txt')));

		$temporary = $this->createMock(Temporary::class);
		$temporary->method('instanceOfStorage')->willReturn(false);
		$temporary->expects($this->never())->method('getLocalFile');
		$this->assertEquals([], $backend->getVersionsForFile($user, $this->getFile($temporary, 'notes.txt')));
	}

	public function testVersionsFromOtherBackends() {
		$backend = $this->getBackend(true);
		$other = $this->createMock(\OCA\Files_Versions\Versions\IVersion::class);
		$this->assertFalse($backend->rollback($other));
		$this->assertFalse($backend->read($other));
	}

	public function testUnknownRevision() {
		$backend = $this->getBackend(true);
		$storage = new Local(['datadir' => $this->baseDir . '/Michele']);
		$this->expectException(\OCP\Files\NotFoundException::class);
		$backend->getVersionFile($this->createMock(IUser::class), $this->getFile($storage, 'Documents/notes.txt'), 'does-not-exist');
	}

	public function testVersionMetadata() {
		$backend = $this->getBackend(true);
		$user = $this->createMock(IUser::class);
		$file = $this->getFile(new Local(['datadir' => $this->baseDir . '/Michele']), 'Documents/notes.txt');

		$version = $backend->getVersionsForFile($user, $file)[0];
		$this->assertSame($backend, $version->getBackend());
		$this->assertSame($file, $version->getSourceFile());
		$this->assertSame($user, $version->getUser());
		$this->assertEquals('notes.txt', $version->getSourceFileName());
		$this->assertEquals('text/plain', $version->getMimeType());
	}

	public function testCreateVersionAndRevision() {
		$backend = $this->getBackend(true);
		// versions are created by taking snapshots, outside of Nextcloud
		$this->assertNull($backend->createVersion($this->createMock(IUser::class), $this->createMock(FileInfo::class)));

		$node = $this->createMock(\OC\Files\Node\Node::class);
		$node->method('getMTime')->willReturn(1234);
		$this->assertSame(1234, $backend->getRevision($node));
	}
}
