<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
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

$config = \OC::$server->getConfig();
$installedVersion = $config->getAppValue('files_snapshots', 'installed_version');

// since 0.3.0 we no longer automatically surround the format with '*', add them to the configured format during upgrade to not break existing setups
if (version_compare($installedVersion, '0.3.0', '<')) {
	$format = $config->getAppValue('files_snapshots', 'date_format', 'Y-m-d_H:i:s');
	$config->setAppValue('files_snapshots', 'date_format', '*' . $format . '*');
}
