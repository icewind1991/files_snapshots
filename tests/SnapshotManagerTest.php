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
use OCA\Files_Snapshots\SnapshotManager;
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
		// snapshots with a different prefix
		mkdir("$basedir/pre2/autosnap_2021-02-24_12:53:43_daily/sub", 0777, true);
	}

	public function testListSnapshotsNotConfigured() {
		$manager = new SnapshotManager("", '*Y-m-d_H:i:s*');
		$this->assertEquals([], iterator_to_array($manager->listAllSnapshots()));
		$this->assertEquals([], $manager->listSnapshotsForFile("dummy"));
		$this->assertEquals(null, $manager->getSnapshot("dummy"));
	}

	public function testListSnapshots() {
		$manager = new SnapshotManager("/" . $this->baseDir . "/pre1/%snapshot%/sub", "*Y-m-d_H:i:s*");

		/** @var Snapshot[] $snapshots */
		$snapshots = iterator_to_array($manager->listAllSnapshots());
		$this->assertCount(5, $snapshots);
		usort($snapshots, function (Snapshot $a, Snapshot $b) {
			return $a->getName() <=> $b->getName();
		});
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-21_19:16:36"), $snapshots[0]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-21_20:16:36"), $snapshots[1]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-22_12:53:43"), $snapshots[2]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-23_12:53:43"), $snapshots[3]->getSnapshotDate());
		$this->assertEquals(null, $snapshots[4]->getSnapshotDate());
	}

	public function testListSnapshotsForFile() {
		$manager = new SnapshotManager("/" . $this->baseDir . "/pre1/%snapshot%/sub", "*Y-m-d_H:i:s*");

		$snapshots = $manager->listSnapshotsForFile("test.txt");
		$this->assertCount(2, $snapshots);
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-21_19:16:36"), $snapshots[0]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-22_12:53:43"), $snapshots[1]->getSnapshotDate());

		$this->assertEquals('old', stream_get_contents($snapshots[0]->readFile('test.txt')));
		$this->assertEquals('new', stream_get_contents($snapshots[1]->readFile('test.txt')));
	}

	public function testListSnapshotsGlob() {
		$manager = new SnapshotManager("/" . $this->baseDir . "/*/%snapshot%/sub", "*Y-m-d_H:i:s*");

		/** @var Snapshot[] $snapshots */
		$snapshots = iterator_to_array($manager->listAllSnapshots());
		$this->assertCount(6, $snapshots);
		usort($snapshots, function (Snapshot $a, Snapshot $b) {
			return $a->getPath() <=> $b->getPath();
		});
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-21_19:16:36"), $snapshots[0]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-21_20:16:36"), $snapshots[1]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-22_12:53:43"), $snapshots[2]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-23_12:53:43"), $snapshots[3]->getSnapshotDate());
		$this->assertEquals(null, $snapshots[4]->getSnapshotDate());
		$this->assertEquals(DateTime::createFromFormat("Y-m-d_H:i:s", "2021-02-24_12:53:43"), $snapshots[5]->getSnapshotDate());
	}
}
