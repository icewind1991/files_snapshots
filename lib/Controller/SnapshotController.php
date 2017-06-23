<?php
/**
 * @copyright Copyright (c) 2017 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL snapshot 3 or any later snapshot
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either snapshot 3 of the
 * License, or (at your option) any later snapshot.
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

namespace OCA\Files_Snapshots\Controller;


use OCA\Files_Snapshots\DownloadResponse;
use OCA\Files_Snapshots\Snapshot;
use OCA\Files_Snapshots\SnapshotManager;
use OCP\AppFramework\Controller;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class SnapshotController extends Controller {
	/** @var SnapshotManager */
	private $snapshotManager;

	/** @var Folder */
	private $userFolder;

	public function __construct($appName,
	                            IRequest $request,
	                            SnapshotManager $snapshotManager,
	                            Folder $userFolder
	) {
		parent::__construct($appName, $request);
		$this->snapshotManager = $snapshotManager;
		$this->userFolder = $userFolder;
	}

	/**
	 * @param $source
	 * @param $start
	 * @return array
	 * @NoAdminRequired
	 */
	public function get($source, $start) {
		$node = $this->userFolder->get($source);
		if (!$node) {
			throw new NotFoundException();
		}
		$path = $node->getPath();
		$snapshots = $this->snapshotManager->listSnapshotsForFile($path);

		$snapshots = array_map(function (Snapshot $snapshot) use ($path) {
			return [
				'snapshot' => $snapshot->getName(),
				'mtime' => $snapshot->getMtime($path),
				'preview' => '',
				'mimetype' => \OC::$server->getMimeTypeDetector()->detectPath($path),
				'size' => $snapshot->getSize($path)
			];
		}, $snapshots);

		usort($snapshots, function ($a, $b) {
			return $b['mtime'] - $a['mtime'];
		});

		return [
			'snapshots' => array_values($snapshots),
			'endReached' => true
		];
	}

	/**
	 * @param $file
	 * @param $revision
	 * @throws NotFoundException
	 * @NoCSRFRequired
	 * @NoAdminRequired
	 */
	public function download($file, $revision) {
		$node = $this->userFolder->get($file);
		if (!$node) {
			throw new NotFoundException();
		}
		$path = $node->getPath();

		$snapshot = $this->snapshotManager->getSnapshot($revision);
		if (!$snapshot || !$snapshot->hasFile($path)) {
			throw new NotFoundException();
		}

		$handle = $snapshot->readFile($path);

		return new DownloadResponse($handle, $snapshot->getSize($path), $node->getName(), $snapshot->getMtime($path), $node->getMimetype());
	}

	/**
	 * @param $file
	 * @param $revision
	 * @throws NotFoundException
	 * @NoAdminRequired
	 */
	public function rollback($file, $revision) {
		$node = $this->userFolder->get($file);
		if (!$node instanceof File) {
			throw new NotFoundException();
		}
		$path = $node->getPath();

		$snapshot = $this->snapshotManager->getSnapshot($revision);
		if (!$snapshot || !$snapshot->hasFile($path)) {
			throw new NotFoundException();
		}

		$source = $snapshot->readFile($path);

		$target = $node->fopen('w');
		stream_copy_to_stream($source, $target);
		fclose($source);
		fclose($target);

		return [
			'revision' => $revision,
			'file' => $file
		];
	}
}