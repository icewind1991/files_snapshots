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

use OCA\Files_Snapshots\ExternalStorages;
use OCA\Files_Snapshots\Settings\Admin;
use OCA\Files_Snapshots\SnapshotManager;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AdminSettingsTest extends TestCase {
	private function getAppConfig(array $values): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn ($_app, $key, $default = '') => $values[$key] ?? $default);
		$appConfig->method('getValueArray')->willReturnCallback(fn ($_app, $key, $default = []) => $values[$key] ?? $default);
		$appConfig->method('getValueBool')->willReturnCallback(fn ($_app, $key, $default = false) => $values[$key] ?? $default);
		return $appConfig;
	}

	public function testFormDefaults() {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->with('files_versions')->willReturn(true);

		$params = (new Admin($this->getAppConfig([]), $appManager))->getForm()->getParams();
		$this->assertEquals([
			'versions_enabled' => true,
			'snapshot_format' => '',
			'date_format' => 'Y-m-d_H:i:s',
			'mount_formats' => [],
			'autodetect' => false,
			'autodetect_patterns' => SnapshotManager::DEFAULT_AUTODETECT_PATTERNS,
		], $params);
	}

	public function testFormSavedValuesAndVersionsDisabled() {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(false);

		$params = (new Admin($this->getAppConfig([
			'snap_format' => '/snaps/%snapshot%',
			'date_format' => 'Y-m-d',
			// values are sanitized when shown, in case they were set with occ
			'mount_formats' => [['source' => '/mnt/a/', 'format' => '/s/%snapshot%'], ['source' => 'relative', 'format' => '/s/%snapshot%']],
			'autodetect' => true,
			'autodetect_patterns' => ['.snap/%snapshot%', '%snapshot%'],
		]), $appManager))->getForm()->getParams();

		$this->assertFalse($params['versions_enabled']);
		$this->assertEquals([['source' => '/mnt/a', 'format' => '/s/%snapshot%']], $params['mount_formats']);
		$this->assertEquals(['.snap/%snapshot%'], $params['autodetect_patterns']);
		$this->assertTrue($params['autodetect']);
	}

	private function renderTemplate(array $params): string {
		// the template helpers (p(), script(), ...) are normally loaded when rendering a template
		require_once \OC::$SERVERROOT . '/lib/private/Template/functions.php';
		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$_ = $params;
		ob_start();
		include __DIR__ . '/../templates/settings.php';
		return (string)ob_get_clean();
	}

	public function testRenderedWarning() {
		foreach ([false, true] as $versionsEnabled) {
			$appManager = $this->createMock(IAppManager::class);
			$appManager->method('isEnabledForAnyone')->willReturn($versionsEnabled);
			$params = (new Admin($this->getAppConfig(['mount_formats' => [['source' => '/mnt/a', 'format' => '/s/%snapshot%']]]), $appManager))->getForm()->getParams();
			$html = $this->renderTemplate($params);

			$this->assertSame(!$versionsEnabled, str_contains($html, 'class="warning"'));
			$this->assertStringContainsString('id="autodetect_patterns"', $html);
			$this->assertStringContainsString('value="/mnt/a"', $html);
			$this->assertStringContainsString('.zfs/snapshot/%snapshot%', $html);
		}
	}

	public function testSectionAndPriority() {
		$admin = new Admin($this->getAppConfig([]), $this->createMock(IAppManager::class));
		$this->assertEquals('files_snapshots', $admin->getSection());
		$this->assertEquals(5, $admin->getPriority());
	}

	public function testExternalStoragesErrorsAreLogged() {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForAnyone')->willReturn(true);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('broken'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->assertEquals([], (new ExternalStorages($appManager, $container, $logger))->getLocalStorages());
	}
}
