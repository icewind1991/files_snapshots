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

use OC\Files\SetupManager;
use OC\User\Session;
use OCA\Files_Sharing\AppInfo\Application as SharingApplication;
use OCA\Files_Sharing\External\MountProvider as ExternalMountProvider;
use OCA\Files_Sharing\ISharedStorage;
use OCA\Files_Sharing\MountProvider;
use OCA\Files_Snapshots\ExternalStorages;
use OCA\Files_Snapshots\SnapshotManager;
use OCA\Files_Snapshots\Tests\TestSnapshotManager;
use OCA\Files_Snapshots\Versions\SnapshotVersion;
use OCA\Files_Snapshots\Versions\SnapshotVersionBackend;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Test\TestCase;
use Test\Traits\UserTrait;

/**
 * Snapshots through a real share between two users, and listing real external storages
 *
 * @group DB
 */
class SharesAndExternalStorageTest extends TestCase {
	use UserTrait;

	private string $owner;
	private string $recipient;

	private string $baseDir;
	/** @var list<int> */
	private array $createdStorages = [];
	private bool $externalWasEnabled = true;

	protected function setUp(): void {
		parent::setUp();
		$this->baseDir = rtrim(Server::get(ITempManager::class)->getTemporaryFolder(), '/');

		\OC_App::loadApp('files_sharing');
		(new SharingApplication())->registerMountProviders(
			Server::get(IMountProviderCollection::class),
			Server::get(MountProvider::class),
			Server::get(ExternalMountProvider::class),
		);
		// unique users, so earlier runs can't interfere
		$suffix = bin2hex(random_bytes(4));
		$this->owner = 'snapshots-owner-' . $suffix;
		$this->recipient = 'snapshots-recipient-' . $suffix;
		$this->createUser($this->owner, $this->owner);
		$this->createUser($this->recipient, $this->recipient);
		// not a first login, which would trigger unrelated first login handlers (like creating example files and contacts)
		foreach ([$this->owner, $this->recipient] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->updateLastLoginTimestamp();
		}
	}

	protected function tearDown(): void {
		// only remove what this test created
		$connection = Server::get(IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->delete('share')
			->where($qb->expr()->in('uid_owner', $qb->createNamedParameter([$this->owner, $this->recipient], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		$qb = $connection->getQueryBuilder();
		$qb->delete('mounts')
			->where($qb->expr()->in('user_id', $qb->createNamedParameter([$this->owner, $this->recipient], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeStatement();
		foreach ([$this->owner, $this->recipient] as $uid) {
			$storage = \OC\Files\Cache\Storage::getStorageById('home::' . $uid);
			if ($storage !== null) {
				\OC\Files\Cache\Storage::removeFileCacheEntries((int)$storage['numeric_id']);
				$qb = $connection->getQueryBuilder();
				$qb->delete('storages')
					->where($qb->expr()->eq('numeric_id', $qb->createNamedParameter((int)$storage['numeric_id'], IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
			self::removeDirectory(Server::get(IConfig::class)->getSystemValueString('datadirectory') . '/' . $uid);
		}

		Server::get(IUserSession::class)->setUser(null);
		Server::get(SetupManager::class)->tearDown();

		if ($this->createdStorages) {
			$service = Server::get('OCA\Files_External\Service\GlobalStoragesService');
			foreach ($this->createdStorages as $id) {
				$service->removeStorage($id);
			}
		}
		if (!$this->externalWasEnabled) {
			Server::get(IAppManager::class)->disableApp('files_external');
		}
		parent::tearDown();
	}

	private static function removeDirectory(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($items as $item) {
			$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}

	/**
	 * Log in like the files_sharing tests do
	 */
	private function login(string $uid): void {
		Server::get(SetupManager::class)->tearDown();
		$user = Server::get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		/** @var Session $session */
		$session = Server::get(IUserSession::class);
		$session->completeLogin($user, ['loginName' => $uid, 'password' => $uid], false);
		$rootFolder = Server::get(IRootFolder::class);
		if (!$rootFolder->nodeExists('/' . $uid . '/files')) {
			$rootFolder->newFolder('/' . $uid . '/files');
		}
	}

	private function getManager(string $snapshotFormat): SnapshotManager {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $key, $default = '') => match ($key) {
			'snap_format' => $snapshotFormat,
			'date_format' => 'Y-m-d',
			default => $default,
		});
		$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $_key, $default = []) => $default);
		$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $_key, $default = false) => $default);
		// the real data directory, so the owner's home storage matches the snapshot format
		return new TestSnapshotManager($appConfig, Server::get(IConfig::class));
	}

	private function getUser(string $uid): IUser {
		$user = Server::get(IUserManager::class)->get($uid);
		$this->assertNotNull($user);
		return $user;
	}

	public function testVersionsOfSharedFile() {
		// owner creates a file and shares it
		$this->login($this->owner);
		$ownerFolder = Server::get(IRootFolder::class)->getUserFolder($this->owner);
		$file = $ownerFolder->newFile('shared.txt', 'current');

		$shareManager = Server::get(IShareManager::class);
		$share = $shareManager->newShare();
		$share->setNode($file)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($this->recipient)
			->setSharedBy($this->owner)
			->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE);
		$share = $shareManager->createShare($share);
		$share->setStatus(IShare::STATUS_ACCEPTED);
		$shareManager->updateShare($share);

		// a snapshot of the data directory containing an older version of the owner's file
		mkdir($this->baseDir . '/snaps/snap-2026-11-01/' . $this->owner . '/files', 0777, true);
		file_put_contents($this->baseDir . '/snaps/snap-2026-11-01/' . $this->owner . '/files/shared.txt', 'old');
		touch($this->baseDir . '/snaps/snap-2026-11-01/' . $this->owner . '/files/shared.txt', 1000);
		$backend = new SnapshotVersionBackend($this->getManager($this->baseDir . '/snaps/%snapshot%'));

		// recipient sees the snapshot through the share
		$this->login($this->recipient);
		$sharedFile = Server::get(IRootFolder::class)->getUserFolder($this->recipient)->get('shared.txt');
		$this->assertInstanceOf(File::class, $sharedFile);
		$this->assertTrue($sharedFile->getStorage()->instanceOfStorage(ISharedStorage::class), 'the file should be on a shared storage');

		$this->assertTrue($backend->useBackendForStorage($sharedFile->getStorage()));
		/** @var SnapshotVersion[] $versions */
		$versions = $backend->getVersionsForFile($this->getUser($this->recipient), $sharedFile);
		$this->assertCount(1, $versions);
		$this->assertEquals('snap-2026-11-01', $versions[0]->getRevisionId());
		$this->assertEquals($this->owner . '/files/shared.txt', $versions[0]->getVersionPath());
		$this->assertEquals('old', stream_get_contents($backend->read($versions[0])));

		// restoring as the recipient updates the owner's file
		$this->assertTrue($backend->rollback($versions[0]));
		$this->assertEquals('old', $ownerFolder->get('shared.txt')->getContent());

		// without snapshots, shares keep the regular versions
		$this->assertFalse((new SnapshotVersionBackend($this->getManager('')))->useBackendForStorage($sharedFile->getStorage()));
	}

	public function testListsExternalStoragesForSpecificUsers() {
		$appManager = Server::get(IAppManager::class);
		if (!$appManager->isInstalled('files_external') && !is_dir(\OC::$SERVERROOT . '/apps/files_external')) {
			$this->markTestSkipped('files_external is not available');
		}
		$this->externalWasEnabled = $appManager->isEnabledForAnyone('files_external');
		if (!$this->externalWasEnabled) {
			$appManager->enableApp('files_external');
		}
		$appManager->loadApp('files_external');

		mkdir($this->baseDir . '/ext-user', 0777, true);
		mkdir($this->baseDir . '/ext-everyone', 0777, true);
		$service = Server::get('OCA\Files_External\Service\GlobalStoragesService');
		foreach ([
			['/SnapshotsUser', $this->baseDir . '/ext-user', [$this->owner]],
			['/SnapshotsEveryone', $this->baseDir . '/ext-everyone', []],
		] as [$mountPoint, $datadir, $users]) {
			$storage = $service->createStorage($mountPoint, 'local', 'null::null', ['datadir' => $datadir], [], $users, []);
			$this->createdStorages[] = $service->addStorage($storage)->getId();
		}

		// a new instance, as the app would get it in a request where files_external isn't loaded yet
		$storages = new ExternalStorages($appManager, \OC::$server, Server::get(\Psr\Log\LoggerInterface::class));
		$found = array_column($storages->getLocalStorages(), 'datadir', 'mountPoint');
		$this->assertEquals($this->baseDir . '/ext-user', $found['/SnapshotsUser'] ?? null, 'storages for specific users are listed');
		$this->assertEquals($this->baseDir . '/ext-everyone', $found['/SnapshotsEveryone'] ?? null);
	}
}
