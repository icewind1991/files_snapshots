<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Robin Appelman <robin@icewind.nl>
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

namespace OCA\Files_Snapshots\Tests;

use DateTime;
use OCA\Files_Snapshots\Snapshot;
use OCA\Files_Snapshots\SnapshotFormat;
use OCA\Files_Snapshots\SnapshotManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use Test\TestCase;

class SnapshotManagerTest extends TestCase {
	/** @var ITempManager */
	private $tempManager;
	/** @var string */
	private $baseDir;

	protected function setUp(): void {
		parent::setUp();

		$this->tempManager = \OC::$server->query(ITempManager::class);

		$basedir = $this->tempManager->getTemporaryFolder();
		mkdir("$basedir/pre1");
		$this->baseDir = $basedir;

		// oldest snapshot, contains the file
		mkdir("$basedir/pre1/autosnap_2021-02-21_19:16:36_daily/sub", 0777, true);
		file_put_contents("$basedir/pre1/autosnap_2021-02-21_19:16:36_daily/sub/test.txt", 'old');
		touch("$basedir/pre1/autosnap_2021-02-21_19:16:36_daily/sub/test.txt", 100);

		// newer snapshot, but file remains unchanged
		mkdir("$basedir/pre1/autosnap_2021-02-21_20:16:36_hourly/sub", 0777, true);
		file_put_contents("$basedir/pre1/autosnap_2021-02-21_20:16:36_hourly/sub/test.txt", 'old');
		touch("$basedir/pre1/autosnap_2021-02-21_20:16:36_hourly/sub/test.txt", 100);

		// new snapshot, file has been updated
		mkdir("$basedir/pre1/autosnap_2021-02-22_12:53:43_weekly/sub", 0777, true);
		file_put_contents("$basedir/pre1/autosnap_2021-02-22_12:53:43_weekly/sub/test.txt", 'new');
		touch("$basedir/pre1/autosnap_2021-02-21_19:16:36_daily/sub/test.txt", 110);

		// new snapshot that no longer contains the file
		mkdir("$basedir/pre1/autosnap_2021-02-23_12:53:43_daily/sub", 0777, true);
		// snapshot that doesn't match the autosnap date
		mkdir("$basedir/pre1/non-date-snap/sub", 0777, true);
		touch("$basedir/pre1/non-date-snap/sub", 1714137760);
		// snapshots with a different prefix
		mkdir("$basedir/pre2/autosnap_2021-02-24_12:53:43_daily/sub", 0777, true);

		// snapshots without a date
		mkdir("$basedir/nodate/snap1/sub", 0777, true);
		file_put_contents("$basedir/nodate/snap1/sub/test.txt", 'old');
		touch("$basedir/nodate/snap1/sub/test.txt", 100);
		touch("$basedir/nodate/snap1/sub", 100);
		mkdir("$basedir/nodate/snap2/sub", 0777, true);
		file_put_contents("$basedir/nodate/snap2/sub/test.txt", 'new');
		touch("$basedir/nodate/snap2/sub/test.txt", 110);
		touch("$basedir/nodate/snap2/sub", 110);
	}

	private function getManager(
		string $snapshotFormat,
		string $dateFormat = 'Y-m-d_H:i:s',
		array $mountFormats = [],
		bool $autodetect = false,
		string $dataDirectory = '/var/www/data',
		array $autodetectPatterns = SnapshotManager::DEFAULT_AUTODETECT_PATTERNS,
	): SnapshotManager {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(fn ($_app, $key, $default = '') => match ($key) {
				'snap_format' => $snapshotFormat,
				'date_format' => $dateFormat,
				default => $default,
			});
		$appConfig->method('getValueArray')
			->willReturnCallback(fn ($_app, $key, $default = []) => match ($key) {
				'mount_formats' => $mountFormats,
				'autodetect_patterns' => $autodetectPatterns,
				default => $default,
			});
		$appConfig->method('getValueBool')
			->willReturnCallback(fn ($_app, $key, $default = false) => $key === 'autodetect' ? $autodetect : $default);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')
			->willReturnCallback(fn ($key, $default = '') => $key === 'datadirectory' ? $dataDirectory : $default);
		return new TestSnapshotManager($appConfig, $config);
	}

	public function testListSnapshotsNotConfigured() {
		$manager = $this->getManager('', '*Y-m-d_H:i:s*');
		$this->assertEquals([], iterator_to_array($manager->listAllSnapshots()));
		$this->assertEquals([], $manager->listSnapshotsForFile('dummy'));
		$this->assertEquals(null, $manager->getSnapshot('dummy'));
	}

	public function testListSnapshots() {
		$manager = $this->getManager('/' . $this->baseDir . '/pre1/%snapshot%/sub', '*Y-m-d_H:i:s*');

		/** @var Snapshot[] $snapshots */
		$snapshots = iterator_to_array($manager->listAllSnapshots());
		$this->assertCount(5, $snapshots);
		usort($snapshots, function (Snapshot $a, Snapshot $b) {
			return $a->getName() <=> $b->getName();
		});
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-21_19:16:36'), $snapshots[0]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-21_20:16:36'), $snapshots[1]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-22_12:53:43'), $snapshots[2]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-23_12:53:43'), $snapshots[3]->getSnapshotDate());
		$this->assertEquals(new DateTime('@1714137760'), $snapshots[4]->getSnapshotDate());
	}

	public function testListSnapshotsForFile() {
		$manager = $this->getManager('/' . $this->baseDir . '/pre1/%snapshot%/sub', '*Y-m-d_H:i:s*');

		$snapshots = $manager->listSnapshotsForFile('test.txt');
		$this->assertCount(2, $snapshots);
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-21_19:16:36'), $snapshots[0]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-22_12:53:43'), $snapshots[1]->getSnapshotDate());

		$this->assertEquals('old', stream_get_contents($snapshots[0]->readFile('test.txt')));
		$this->assertEquals('new', stream_get_contents($snapshots[1]->readFile('test.txt')));
	}

	public function testListSnapshotsForFileNoDate() {
		$manager = $this->getManager('/' . $this->baseDir . '/nodate/%snapshot%/sub', '*snap*');

		$snapshots = $manager->listSnapshotsForFile('test.txt');
		$this->assertCount(2, $snapshots);

		$this->assertEquals('old', stream_get_contents($snapshots[0]->readFile('test.txt')));
		$this->assertEquals('new', stream_get_contents($snapshots[1]->readFile('test.txt')));
	}

	public function testListSnapshotsGlob() {
		$manager = $this->getManager('/' . $this->baseDir . '/pre*/%snapshot%/sub', '*Y-m-d_H:i:s*');

		/** @var Snapshot[] $snapshots */
		$snapshots = iterator_to_array($manager->listAllSnapshots());
		$this->assertCount(6, $snapshots);
		usort($snapshots, function (Snapshot $a, Snapshot $b) {
			return $a->getPath() <=> $b->getPath();
		});
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-21_19:16:36'), $snapshots[0]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-21_20:16:36'), $snapshots[1]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-22_12:53:43'), $snapshots[2]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-23_12:53:43'), $snapshots[3]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d_H:i:s', '2021-02-24_12:53:43'), $snapshots[5]->getSnapshotDate());
		$this->assertEquals(new DateTime('@1714137760'), $snapshots[4]->getSnapshotDate());
	}

	/**
	 * Create a fake zfs dataset at $root with snapshots containing $file
	 *
	 * @param array<string, string> $snapshots snapshot name => file content
	 */
	private function createZfsDataset(string $root, string $file, array $snapshots): void {
		$mtime = 100;
		foreach ($snapshots as $name => $content) {
			$path = "$root/.zfs/snapshot/$name/$file";
			mkdir(dirname($path), 0777, true);
			file_put_contents($path, $content);
			touch($path, $mtime);
			$mtime += 10;
		}
		if (!is_dir(dirname("$root/$file"))) {
			mkdir(dirname("$root/$file"), 0777, true);
		}
		file_put_contents("$root/$file", 'current');
	}

	public function testResolveDataDirectory() {
		$manager = $this->getManager('/' . $this->baseDir . '/pre1/%snapshot%/sub', '*Y-m-d_H:i:s*', [], false, '/var/www/data/');

		$location = $manager->resolve('/var/www/data/test.txt');
		$this->assertNotNull($location);
		$this->assertEquals('test.txt', $location->relativePath);
		$this->assertCount(2, $manager->listSnapshotsForLocation($location));

		$this->assertNull($manager->resolve('/var/www/database/test.txt'));
		$this->assertNull($manager->resolve('/somewhere/else/test.txt'));
	}

	public function testResolveNotConfigured() {
		$manager = $this->getManager('');
		$this->assertNull($manager->resolve('/var/www/data/test.txt'));
	}

	public function testResolveMountFormatLongestMatch() {
		$base = $this->baseDir;
		$this->createZfsDataset("$base/external", 'dir/file.txt', [
			'zfs-auto-snap_daily-2026-11-01-1325' => 'v1',
			'zfs-auto-snap_daily-2026-11-02-1325' => 'v2',
		]);
		$manager = $this->getManager('/' . $base . '/pre1/%snapshot%/sub', '*-*-*-Y-m-d-Hi', [
			['source' => '/', 'format' => '/nonexistent/%snapshot%'],
			['source' => "$base/external/", 'format' => "$base/external/.zfs/snapshot/%snapshot%"],
			['source' => 'relative/path', 'format' => '/ignored/%snapshot%'],
			['source' => "$base/invalid", 'format' => '/missing-placeholder'],
		], false, $base . '/data');

		$location = $manager->resolve("$base/external/dir/file.txt");
		$this->assertNotNull($location);
		$this->assertEquals('dir/file.txt', $location->relativePath);
		$snapshots = $manager->listSnapshotsForLocation($location);
		$this->assertCount(2, $snapshots);
		$this->assertEquals('zfs-auto-snap_daily-2026-11-01-1325', $snapshots[0]->getName());
		$this->assertEquals(DateTime::createFromFormat('Y-m-d-Hi', '2026-11-02-1325'), $snapshots[1]->getSnapshotDate());
		$this->assertEquals('v2', stream_get_contents($snapshots[1]->readFile($location->relativePath)));

		// the catch-all "/" mapping is used for anything else
		$other = $manager->resolve('/some/other/file.txt');
		$this->assertNotNull($other);
		$this->assertEquals('some/other/file.txt', $other->relativePath);
	}

	public function testResolveZfsAutodetect() {
		$base = $this->baseDir;
		$this->createZfsDataset("$base/pool/Michele", 'Documents/report.odt', [
			'zfs-auto-snap_daily-2026-11-14-1325' => 'zero',
			'zfs-auto-snap_hourly-2026-11-15-1300' => 'one',
			'zfs-auto-snap_hourly-2026-11-15-1400' => 'one', // unchanged, mtime is updated below
		]);
		touch("$base/pool/Michele/.zfs/snapshot/zfs-auto-snap_hourly-2026-11-15-1400/Documents/report.odt", 110);

		$manager = $this->getManager('', '*-*-*-Y-m-d-Hi', [], false);
		$this->assertNull($manager->resolve("$base/pool/Michele/Documents/report.odt"));

		$manager = $this->getManager('', '*-*-*-Y-m-d-Hi', [], true);
		$expected = ['root' => rtrim($base, '/') . '/pool/Michele', 'pattern' => '.zfs/snapshot/%snapshot%'];
		$this->assertEquals($expected, $manager->detectSnapshotDirectory("$base/pool/Michele/Documents/report.odt"));
		$this->assertEquals($expected, $manager->detectSnapshotDirectory("$base/pool/Michele"));
		$this->assertNull($manager->detectSnapshotDirectory("$base/pool"));

		// not detected when the pattern is not enabled
		$manager = $this->getManager('', '*-*-*-Y-m-d-Hi', [], true, autodetectPatterns: ['.snapshots/%snapshot%/snapshot']);
		$this->assertNull($manager->resolve("$base/pool/Michele/Documents/report.odt"));
		$manager = $this->getManager('', '*-*-*-Y-m-d-Hi', [], true);

		$location = $manager->resolve("$base/pool/Michele/Documents/report.odt");
		$this->assertNotNull($location);
		$this->assertEquals('Documents/report.odt', $location->relativePath);
		$snapshots = $manager->listSnapshotsForLocation($location);
		$this->assertEquals(['zfs-auto-snap_daily-2026-11-14-1325', 'zfs-auto-snap_hourly-2026-11-15-1300'], array_map(fn (Snapshot $snapshot) => $snapshot->getName(), $snapshots));
		$this->assertEquals('zero', stream_get_contents($snapshots[0]->readFile($location->relativePath)));

		// storage root resolves to the dataset itself
		$root = $manager->resolve("$base/pool/Michele/");
		$this->assertNotNull($root);
		$this->assertEquals('', $root->relativePath);
	}

	public function testResolveConfiguredBeforeAutodetect() {
		$base = $this->baseDir;
		$this->createZfsDataset("$base/pool/data", 'admin/files/test.txt', [
			'snap1' => 'old',
		]);
		mkdir("$base/manual/snap2/admin/files", 0777, true);
		file_put_contents("$base/manual/snap2/admin/files/test.txt", 'manual');

		$manager = $this->getManager("$base/manual/%snapshot%", 'Y-m-d', [], true, "$base/pool/data");
		$location = $manager->resolve("$base/pool/data/admin/files/test.txt");
		$this->assertNotNull($location);
		$snapshots = $manager->listSnapshotsForLocation($location);
		$this->assertCount(1, $snapshots);
		$this->assertEquals('snap2', $snapshots[0]->getName());
	}

	public function testZfsDatasetWithGlobCharacters() {
		$base = $this->baseDir;
		$this->createZfsDataset("$base/TV Series [HD]", 'show.mkv', [
			'snap1' => 'old',
		]);
		$manager = $this->getManager('', 'Y-m-d', [], true);
		$location = $manager->resolve("$base/TV Series [HD]/show.mkv");
		$this->assertNotNull($location);
		$this->assertCount(1, $manager->listSnapshotsForLocation($location));
	}

	public function testSymlinksOutOfSnapshotsAreNotFollowed() {
		$base = rtrim($this->baseDir, '/');
		mkdir("$base/outside", 0777, true);
		file_put_contents("$base/outside/secret.txt", 'secret');
		mkdir("$base/snap/s1/files", 0777, true);
		file_put_contents("$base/snap/s1/files/real.txt", 'real');
		// symlinks a user created before the snapshot was taken
		symlink("$base/outside/secret.txt", "$base/snap/s1/files/leak.txt");
		symlink("$base/outside", "$base/snap/s1/files/leakdir");
		symlink("$base/snap/s1/files/real.txt", "$base/snap/s1/files/inside.txt");

		$snapshot = new Snapshot("$base/snap/s1", 's1', 'Y-m-d');
		$this->assertTrue($snapshot->hasFile('files/real.txt'));
		$this->assertTrue($snapshot->hasFile('files/inside.txt'));
		$this->assertEquals('real', stream_get_contents($snapshot->readFile('files/inside.txt')));

		foreach (['files/leak.txt', 'files/leakdir/secret.txt', 'files/missing.txt'] as $file) {
			$this->assertFalse($snapshot->hasFile($file), $file);
			$this->assertFalse($snapshot->readFile($file), $file);
			$this->assertSame(0, $snapshot->getSize($file), $file);
			$this->assertSame(0, $snapshot->getMtime($file), $file);
		}
	}

	public function testGetSnapshotRejectsTraversal() {
		$format = new SnapshotFormat('/' . $this->baseDir . '/pre1/%snapshot%/sub', '*Y-m-d_H:i:s*');
		$this->assertNotNull($format->getSnapshot('autosnap_2021-02-21_19:16:36_daily'));
		$this->assertNull($format->getSnapshot('..'));
		$this->assertNull($format->getSnapshot('../pre1/autosnap_2021-02-21_19:16:36_daily'));
		$this->assertNull($format->getSnapshot(''));
	}

	public function testGetSnapshotGlob() {
		$manager = $this->getManager('/' . $this->baseDir . '/pre*/%snapshot%/sub', '*Y-m-d_H:i:s*');
		$snapshot = $manager->getSnapshot('autosnap_2021-02-24_12:53:43_daily');
		$this->assertNotNull($snapshot);
		$this->assertEquals('autosnap_2021-02-24_12:53:43_daily', $snapshot->getName());
	}

	public function testAutodetectSnapper() {
		$base = $this->baseDir;
		// snapper: <subvolume>/.snapshots/<number>/snapshot/... with the date in <subvolume>/.snapshots/<number>/info.xml
		foreach ([1 => ['2026-11-14 10:00:00', 'one', 100], 2 => ['2026-11-15 10:00:00', 'two', 110], 10 => ['2026-11-13 10:00:00', 'zero', 90]] as $number => [$date, $content, $mtime]) {
			mkdir("$base/home/.snapshots/$number/snapshot/user", 0777, true);
			file_put_contents("$base/home/.snapshots/$number/info.xml", "<?xml version=\"1.0\"?>\n<snapshot>\n  <type>single</type>\n  <num>$number</num>\n  <date>$date</date>\n</snapshot>\n");
			file_put_contents("$base/home/.snapshots/$number/snapshot/user/file.txt", $content);
			touch("$base/home/.snapshots/$number/snapshot/user/file.txt", $mtime);
		}
		// a snapshot without the file, and an unrelated directory
		mkdir("$base/home/.snapshots/3/snapshot", 0777, true);
		mkdir("$base/home/.snapshots/junk", 0777, true);
		mkdir("$base/home/user", 0777, true);
		file_put_contents("$base/home/user/file.txt", 'current');

		$manager = $this->getManager('', '*-*-*-Y-m-d-Hi', [], true);
		$location = $manager->resolve("$base/home/user/file.txt");
		$this->assertNotNull($location);
		$this->assertEquals('user/file.txt', $location->relativePath);

		$snapshots = $manager->listSnapshotsForLocation($location);
		// sorted by the date from info.xml, not by number
		$this->assertEquals(['10', '1', '2'], array_map(fn (Snapshot $snapshot) => $snapshot->getName(), $snapshots));
		$this->assertEquals(new DateTime('2026-11-13 10:00:00', new \DateTimeZone('UTC')), $snapshots[0]->getSnapshotDate());
		$this->assertEquals('two', stream_get_contents($snapshots[2]->readFile($location->relativePath)));
	}

	public function testAutodetectCephfs() {
		$base = $this->baseDir;
		// CephFS exposes .snap in every directory, the nearest one is used
		mkdir("$base/ceph/.snap/daily/projects/a", 0777, true);
		mkdir("$base/ceph/projects/a/.snap/daily", 0777, true);
		file_put_contents("$base/ceph/projects/a/.snap/daily/file.txt", 'old');
		file_put_contents("$base/ceph/projects/a/file.txt", 'new');

		$manager = $this->getManager('', 'Y-m-d', [], true);
		$location = $manager->resolve("$base/ceph/projects/a/file.txt");
		$this->assertNotNull($location);
		$this->assertEquals('file.txt', $location->relativePath);
		$snapshots = $manager->listSnapshotsForLocation($location);
		$this->assertCount(1, $snapshots);
		$this->assertEquals('old', stream_get_contents($snapshots[0]->readFile('file.txt')));
	}

	public function testAutodetectPatternOrder() {
		$base = $this->baseDir;
		$this->createZfsDataset("$base/both", 'file.txt', ['zfs1' => 'zfs']);
		mkdir("$base/both/.snapshot/netapp1", 0777, true);
		file_put_contents("$base/both/.snapshot/netapp1/file.txt", 'netapp');

		$manager = $this->getManager('', 'Y-m-d', [], true, autodetectPatterns: ['.snapshot/%snapshot%', '.zfs/snapshot/%snapshot%']);
		$snapshots = $manager->listSnapshotsForLocation($manager->resolve("$base/both/file.txt"));
		$this->assertEquals('netapp1', $snapshots[0]->getName());
	}

	public function testSanitizeAutodetectPatterns() {
		$this->assertEquals([
			'.zfs/snapshot/%snapshot%',
			'.snapshots/%snapshot%/snapshot',
			'#snapshot/%snapshot%',
		], SnapshotManager::sanitizeAutodetectPatterns([
			' /.zfs/snapshot/%snapshot%/ ',
			'.snapshots/%snapshot%/snapshot',
			'#snapshot/%snapshot%',
			'.zfs/snapshot/%snapshot%', // duplicate
			'%snapshot%', // would match any directory
			'%snapshot%/data',
			'../escape/%snapshot%',
			'./.snap/%snapshot%',
			'.snap//%snapshot%',
			'.snap/%snapshot%/%snapshot%',
			'.snap/prefix-%snapshot%',
			'no-placeholder',
			42,
		]));
	}

	public function testUserPlaceholder() {
		$base = rtrim($this->baseDir, '/');
		// one backup folder per user, like /backups/<user>/files/daily.0
		foreach (['alice', 'bob'] as $user) {
			mkdir("$base/home/$user/files/docs", 0777, true);
			mkdir("$base/backups/$user/files/daily.0/docs", 0777, true);
			file_put_contents("$base/backups/$user/files/daily.0/docs/a.txt", $user);
		}
		mkdir("$base/home/carol/files", 0777, true);

		$manager = $this->getManager('', 'Y-m-d', [
			['source' => "$base/home/%user%/files", 'format' => "$base/backups/%user%/files/%snapshot%"],
		]);
		$location = $manager->resolve("$base/home/bob/files/docs/a.txt");
		$this->assertNotNull($location);
		$this->assertEquals('docs/a.txt', $location->relativePath);
		$snapshots = $manager->listSnapshotsForLocation($location);
		$this->assertCount(1, $snapshots);
		$this->assertEquals('daily.0', $snapshots[0]->getName());
		$this->assertEquals('bob', stream_get_contents($snapshots[0]->readFile($location->relativePath)));

		$this->assertNull($manager->resolve("$base/home/bob/other/a.txt"));
		$this->assertNull($manager->resolve("$base/home/files"));
		$this->assertNull($manager->resolve("$base/home/../files/a.txt"));
	}

	public function testUserPlaceholderValidation() {
		$this->assertEquals([
			['source' => '/home/%user%/files', 'format' => '/backup/%user%/%snapshot%'],
			['source' => '/home/%user%', 'format' => '/backup/%user%/%snapshot%'],
		], SnapshotManager::sanitizeMountFormats([
			['source' => '/home/%user%/files', 'format' => '/backup/%user%/%snapshot%'],
			['source' => '/home/%user%', 'format' => '/backup/%user%/%snapshot%'],
			['source' => '/home/%user%/%user%', 'format' => '/backup/%snapshot%'],
			['source' => '/home/prefix-%user%', 'format' => '/backup/%snapshot%'],
		]));
	}

	public function testUserPlaceholderLongestMatch() {
		$base = rtrim($this->baseDir, '/');
		mkdir("$base/specific/s1/x", 0777, true);
		mkdir("$base/generic/alice/s1/files/x", 0777, true);
		$manager = $this->getManager('', 'Y-m-d', [
			['source' => "$base/home/%user%", 'format' => "$base/generic/%user%/%snapshot%"],
			['source' => "$base/home/alice/files", 'format' => "$base/specific/%snapshot%"],
		]);
		$this->assertEquals('x', $manager->resolve("$base/home/alice/files/x")->relativePath);
		$this->assertEquals('files/x', $manager->resolve("$base/home/bob/files/x")->relativePath);
	}

	public static function dateProvider(): array {
		return [
			['zfs-auto-snap_daily-2026-11-15-1350', '2026-11-15 13:50:00'],
			['zfs-auto-snap_frequent-2017-06-23-1930', '2017-06-23 19:30:00'],
			['auto-2026-10-15_23-10', '2026-10-15 23:10:00'],
			['GMT+01_2024-07-26_0755', '2024-07-26 07:55:00'],
			['autosnap_2021-02-21_19:16:36_daily', '2021-02-21 19:16:36'],
			['20260812-164508', '2026-08-12 16:45:08'],
			['nc_data.20191026T0000', '2019-10-26 00:00:00'],
			['backup-2026-11-15', '2026-11-15 00:00:00'],
			['@GMT-2026.11.15-13.50.00', null], // dots between date parts are not detected
			['daily.0', null],
			['49', null],
			['snap-9999-99-99', null],
			['2026-13-01', null],
			['1234567890123', null],
		];
	}

	/**
	 * @dataProvider dateProvider
	 */
	public function testDetectDate(string $name, ?string $expected) {
		$date = Snapshot::detectDate($name);
		$this->assertEquals($expected, $date?->format('Y-m-d H:i:s'));
	}

	public function testConfiguredDateFormatTakesPrecedence() {
		// with the configured format the time is read as hours and minutes swapped
		$snapshot = new Snapshot('/nonexistent', 'snap-2026-11-15-5013', '*-Y-m-d-iH', null);
		$this->assertEquals('2026-11-15 13:50:00', $snapshot->getSnapshotDate()->format('Y-m-d H:i:s'));
		// detected when the format doesn't match
		$snapshot = new Snapshot('/nonexistent', 'snap-2026-11-15-1350', 'U', null);
		$this->assertEquals('2026-11-15 13:50:00', $snapshot->getSnapshotDate()->format('Y-m-d H:i:s'));
	}

	public function testAutodetectStopsAtFilesystemRoot() {
		$base = rtrim($this->baseDir, '/');
		// snapshots of the parent dataset contain only the empty directory the child dataset is mounted on
		mkdir("$base/pool/.zfs/snapshot/daily-2026-11-01/child", 0777, true);
		mkdir("$base/pool/child/sub", 0777, true);
		file_put_contents("$base/pool/child/sub/file.txt", 'data');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $_key, $default = '') => $default);
		$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $_key, $default = []) => $default);
		$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $key, $default = false) => $key === 'autodetect' ? true : $default);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');

		$manager = new class($appConfig, $config, "$base/pool/child") extends TestSnapshotManager {
			public function __construct(
				IAppConfig $appConfig,
				IConfig $config,
				private string $mountPoint,
			) {
				parent::__construct($appConfig, $config);
			}

			protected function isFilesystemRoot(string $dir, string $parent): bool {
				return $dir === $this->mountPoint;
			}
		};
		$this->assertNull($manager->resolve("$base/pool/child/sub/file.txt"));
		$this->assertNull($manager->resolve("$base/pool/child"));

		// without the mount, the parent snapshots are used
		$this->assertNotNull($this->getManager('', 'Y-m-d', [], true)->resolve("$base/pool/child/sub/file.txt"));
	}

	public function testSnapperInfoFallbacks() {
		$base = rtrim($this->baseDir, '/');
		$cases = [
			'invalid' => '<snapshot><date>not a date</date></snapshot>',
			'missing' => null,
			'large' => '<snapshot><date>2026-11-14 10:00:00</date>' . str_repeat(' ', 70000) . '</snapshot>',
		];
		foreach ($cases as $name => $info) {
			mkdir("$base/sn/$name/snapshot", 0777, true);
			touch("$base/sn/$name/snapshot", 1700000000);
			if ($info !== null) {
				file_put_contents("$base/sn/$name/info.xml", $info);
			}
			$snapshot = new Snapshot("$base/sn/$name/snapshot", $name, 'Y-m-d', "$base/sn/$name");
			$this->assertEquals(1700000000, $snapshot->getSnapshotDate()->getTimestamp(), "falls back to the mtime for $name info.xml");
		}
	}

	public function testUserPlaceholderWithGlobCharacters() {
		$base = rtrim($this->baseDir, '/');
		foreach (['a[1]', 'a1', 'b*'] as $user) {
			mkdir("$base/hp/$user/files", 0777, true);
			mkdir("$base/bp/$user/daily.0/files", 0777, true);
			file_put_contents("$base/bp/$user/daily.0/files/f.txt", $user);
		}
		$manager = $this->getManager('', 'Y-m-d', [
			['source' => "$base/hp/%user%/files", 'format' => "$base/bp/%user%/%snapshot%/files"],
		]);
		foreach (['a[1]', 'b*'] as $user) {
			$location = $manager->resolve("$base/hp/$user/files/f.txt");
			$snapshots = $manager->listSnapshotsForLocation($location);
			$this->assertCount(1, $snapshots, $user);
			// the folder name is used literally, "a[1]" doesn't match "a1" and "b*" doesn't match other folders
			$this->assertEquals($user, stream_get_contents($snapshots[0]->readFile($location->relativePath)));
		}
	}

	public function testAutodetectIgnoresUserCreatedSnapshotDirectories() {
		$base = rtrim($this->baseDir, '/');
		// real (read-only) snapshots of the dataset
		mkdir("$base/ds/.zfs/snapshot/daily-2026-11-01/shared/project", 0777, true);
		file_put_contents("$base/ds/.zfs/snapshot/daily-2026-11-01/shared/project/report.txt", 'real old version');
		mkdir("$base/ds/shared/project", 0777, true);
		file_put_contents("$base/ds/shared/project/report.txt", 'current');

		// a user with write access to the shared folder creates a fake snapshot directory through Nextcloud
		mkdir("$base/ds/shared/project/.snapshot/2026-11-02", 0777, true);
		file_put_contents("$base/ds/shared/project/.snapshot/2026-11-02/report.txt", 'malicious');
		touch("$base/ds/shared/project/.snapshot/2026-11-02/" . TestSnapshotManager::USER_CREATED_MARKER);
		// and an empty one, which would hide the real snapshots
		mkdir("$base/ds/shared/.snap", 0777, true);

		$manager = $this->getManager('', 'Y-m-d', [], true);
		$location = $manager->resolve("$base/ds/shared/project/report.txt");
		$this->assertNotNull($location);
		$this->assertEquals('shared/project/report.txt', $location->relativePath, 'the real snapshots of the dataset are used');
		$snapshots = $manager->listSnapshotsForLocation($location);
		$this->assertCount(1, $snapshots);
		$this->assertEquals('real old version', stream_get_contents($snapshots[0]->readFile($location->relativePath)));
	}

	public function testAutodetectChecksEverySnapshot() {
		$base = rtrim($this->baseDir, '/');
		// real snapshots, and fake ones created by a user in a snapshot directory they can write to,
		// "0000" sorts before the real snapshots
		foreach (['0000-fake' => true, 'daily-2026-11-01' => false, 'daily-2026-11-02' => false, 'zzzz-fake' => true] as $name => $fake) {
			mkdir("$base/dataset/.zfs/snapshot/$name/docs", 0777, true);
			file_put_contents("$base/dataset/.zfs/snapshot/$name/docs/a.txt", $name);
			touch("$base/dataset/.zfs/snapshot/$name/docs/a.txt", 1000 + strlen($name) + ($fake ? 100 : 0));
			if ($fake) {
				touch("$base/dataset/.zfs/snapshot/$name/" . TestSnapshotManager::USER_CREATED_MARKER);
			}
		}
		mkdir("$base/dataset/docs", 0777, true);

		$manager = $this->getManager('', '*-Y-m-d', [], true);
		$location = $manager->resolve("$base/dataset/docs/a.txt");
		$this->assertNotNull($location);
		$this->assertEquals(['daily-2026-11-01', 'daily-2026-11-02'], array_map(fn (Snapshot $snapshot) => $snapshot->getName(), iterator_to_array($location->format->listSnapshots(), false)));
		$this->assertNull($location->format->getSnapshot('0000-fake'));
		$this->assertNotNull($location->format->getSnapshot('daily-2026-11-01'));
	}

	public function testAutodetectSnapperWithFakeSnapshot() {
		$base = rtrim($this->baseDir, '/');
		// snapper: .snapshots is a separate subvolume, a user that can write to it could add a numbered snapshot
		foreach ([1 => false, 999 => true] as $number => $fake) {
			mkdir("$base/subvol/.snapshots/$number/snapshot/notes", 0777, true);
			file_put_contents("$base/subvol/.snapshots/$number/info.xml", "<snapshot><date>2026-11-1$number 10:00:00</date></snapshot>");
			file_put_contents("$base/subvol/.snapshots/$number/snapshot/notes/todo.txt", $fake ? 'fake' : 'real');
			if ($fake) {
				touch("$base/subvol/.snapshots/$number/snapshot/" . TestSnapshotManager::USER_CREATED_MARKER);
			}
		}
		mkdir("$base/subvol/notes", 0777, true);

		$manager = $this->getManager('', 'Y-m-d', [], true);
		$location = $manager->resolve("$base/subvol/notes/todo.txt");
		$this->assertNotNull($location);
		$snapshots = $manager->listSnapshotsForLocation($location);
		$this->assertCount(1, $snapshots);
		$this->assertEquals('real', stream_get_contents($snapshots[0]->readFile($location->relativePath)));
	}

	public function testAutodetectIgnoresSymlinks() {
		$base = rtrim($this->baseDir, '/');
		// the snapshots of another user's dataset
		mkdir("$base/other/.zfs/snapshot/daily-2026-11-01/docs", 0777, true);
		file_put_contents("$base/other/.zfs/snapshot/daily-2026-11-01/docs/a.txt", 'other user');
		$manager = $this->getManager('', 'Y-m-d', [], true);

		// a symlink to them as snapshot directory
		mkdir("$base/mine/docs", 0777, true);
		symlink("$base/other/.zfs/snapshot", "$base/mine/.snapshot");
		$this->assertNull($manager->resolve("$base/mine/docs/a.txt"));

		// a symlink to one of them next to real snapshots
		mkdir("$base/ds/.zfs/snapshot/daily-2026-11-02/docs", 0777, true);
		mkdir("$base/ds/docs", 0777, true);
		symlink("$base/other/.zfs/snapshot/daily-2026-11-01", "$base/ds/.zfs/snapshot/zz-link");
		$location = $manager->resolve("$base/ds/docs/a.txt");
		$this->assertNotNull($location);
		$this->assertEquals(['daily-2026-11-02'], array_map(fn (Snapshot $snapshot) => $snapshot->getName(), iterator_to_array($location->format->listSnapshots(), false)));

		// a symlink as the snapshot inside a snapper snapshot
		mkdir("$base/subvolume/.snapshots/1", 0777, true);
		mkdir("$base/subvolume/docs", 0777, true);
		symlink("$base/other/.zfs/snapshot/daily-2026-11-01", "$base/subvolume/.snapshots/1/snapshot");
		$this->assertNull($manager->resolve("$base/subvolume/docs/a.txt"));
	}

	public function testContainsSymlink() {
		$base = rtrim($this->baseDir, '/');
		mkdir("$base/plain/a/b", 0777, true);
		symlink("$base/plain/a", "$base/plain/link");
		$this->assertFalse(TestSnapshotManager::containsSymlinkOnDisk("$base/plain", "$base/plain/a/b"));
		$this->assertFalse(TestSnapshotManager::containsSymlinkOnDisk("$base/plain", "$base/plain/"));
		$this->assertTrue(TestSnapshotManager::containsSymlinkOnDisk("$base/plain", "$base/plain/link/b"));
		// paths outside of the directory are never trusted
		$this->assertTrue(TestSnapshotManager::containsSymlinkOnDisk("$base/plain", "$base/elsewhere"));
	}

	public function testConfiguredLocationsAreTrusted() {
		$base = rtrim($this->baseDir, '/');
		mkdir("$base/backup/b1", 0777, true);
		touch("$base/backup/b1/" . TestSnapshotManager::USER_CREATED_MARKER);
		file_put_contents("$base/backup/b1/file.txt", 'backup');
		$manager = $this->getManager('', 'Y-m-d', [['source' => "$base/live", 'format' => "$base/backup/%snapshot%"]], true);
		$this->assertCount(1, $manager->listSnapshotsForLocation($manager->resolve("$base/live/file.txt")));
	}

	public function testSnapshotFilesystemsOnDisk() {
		$base = rtrim($this->baseDir, '/');
		mkdir("$base/disk/.snapshot/s1", 0777, true);
		mkdir("$base/disk/data", 0777, true);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $_key, $default = '') => $default);
		$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $_key, $default = []) => $default);
		$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $key, $default = false) => $key === 'autodetect' ? true : $default);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');

		// a directory created on the same filesystem is not a snapshot
		$this->assertNull((new SnapshotManager($appConfig, $config))->resolve("$base/disk/data"));

		$manager = new TestSnapshotManager($appConfig, $config);
		$this->assertFalse($manager->isSeparateFilesystemOnDisk("$base/disk/.snapshot/s1", "$base/disk"));
		$this->assertFalse($manager->isSeparateFilesystemOnDisk("$base/missing", "$base/disk"));
		// /proc is a separate filesystem on Linux
		if (is_dir('/proc/self')) {
			$this->assertTrue($manager->isSeparateFilesystemOnDisk('/proc', '/'));
		}
	}

	public function testSnapshotFormatEdgeCases() {
		$invalid = new SnapshotFormat('/no/placeholder', 'Y-m-d');
		$this->assertFalse($invalid->isValid());
		$this->assertSame([], iterator_to_array($invalid->listSnapshots()));
		$this->assertNull($invalid->getSnapshot('anything'));

		$format = new SnapshotFormat('/' . $this->baseDir . '/pre1/%snapshot%/sub', 'Y-m-d');
		$this->assertNull($format->getSnapshot('does-not-exist'));
	}

	public function testFilesystemRootOnDisk() {
		$base = rtrim($this->baseDir, '/');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $_key, $default = '') => $default);
		$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $_key, $default = []) => $default);
		$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $_key, $default = false) => $default);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');
		$manager = new TestSnapshotManager($appConfig, $config);

		$this->assertFalse($manager->isFilesystemRootOnDisk("$base/pre1", $base));
		$this->assertFalse($manager->isFilesystemRootOnDisk("$base/missing", $base));
		if (is_dir('/proc/self')) {
			$this->assertTrue($manager->isFilesystemRootOnDisk('/proc', '/'));
		}
	}
}
