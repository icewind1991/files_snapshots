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
use OCA\Files_Snapshots\SnapshotManager;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ITempManager;
use Test\TestCase;

class AdminControllerTest extends TestCase {
	private string $baseDir;

	protected function setUp(): void {
		parent::setUp();
		$this->baseDir = rtrim(\OC::$server->get(ITempManager::class)->getTemporaryFolder(), '/');
	}

	private function getController(?IAppConfig $appConfig = null): AdminController {
		if ($appConfig === null) {
			$appConfig = $this->createMock(IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $_key, $default = '') => $default);
			$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $_key, $default = []) => $default);
			$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $_key, $default = false) => $default);
		}
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn('');
		return new AdminController('files_snapshots', $this->createMock(IRequest::class), $appConfig, new SnapshotManager($appConfig, $config));
	}

	public function testSettings() {
		$base = $this->baseDir;
		mkdir("$base/snaps/snap-2026-11-02/data", 0777, true);
		mkdir("$base/snaps/snap-2026-11-01/data", 0777, true);
		$this->assertEquals([
			'snap-2026-11-01' => '2026-11-01 00:00:00',
			'snap-2026-11-02' => '2026-11-02 00:00:00',
		], array_map(fn (string $date) => substr($date, 0, 10) . ' 00:00:00', $this->getController()->testSettings("$base/snaps/%snapshot%/data", '*-Y-m-d')));
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

		$result = $this->getController($appConfig)->save(
			'/data/.zfs/snapshot/%snapshot%',
			'*-Y-m-d',
			[
				['source' => '/mnt/a/', 'format' => '/mnt/a/.zfs/snapshot/%snapshot%'],
				['source' => 'relative', 'format' => '/x/%snapshot%'],
				['source' => '/mnt/b', 'format' => '/no-placeholder'],
				'invalid',
			],
		);

		$expected = [
			'snap_format' => '/data/.zfs/snapshot/%snapshot%',
			'date_format' => '*-Y-m-d',
			'mount_formats' => [['source' => '/mnt/a', 'format' => '/mnt/a/.zfs/snapshot/%snapshot%']],
		];
		$this->assertEquals(['files_snapshots' => $expected], $saved);
		$this->assertEquals([
			'snapshotFormat' => $expected['snap_format'],
			'dateFormat' => $expected['date_format'],
			'mountFormats' => $expected['mount_formats'],
		], $result);
	}
}
