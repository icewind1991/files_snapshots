<?php
/**
 * @copyright Copyright (c) 2016, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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
namespace OCA\Files_Versions\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IPreview;
use OCP\IRequest;

class PreviewController extends Controller {

	/** @var IRootFolder */
	private $rootFolder;

	/** @var string */
	private $userId;

	/** @var IMimeTypeDetector */
	private $mimeTypeDetector;

	/** @var IPreview */
	private $previewManager;

	public function __construct($appName,
								IRequest $request,
								IRootFolder $rootFolder,
								$userId,
								IMimeTypeDetector $mimeTypeDetector,
								IPreview $previewManager) {
		parent::__construct($appName, $request);

		$this->rootFolder = $rootFolder;
		$this->userId = $userId;
		$this->mimeTypeDetector = $mimeTypeDetector;
		$this->previewManager = $previewManager;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $file
	 * @param int $x
	 * @param int $y
	 * @param string $version
	 * @return DataResponse|FileDisplayResponse
	 */
	public function getPreview(
		$file = '',
		$x = 44,
		$y = 44,
		$version = ''
	) {
		if ($file === '' || $version === '' || $x === 0 || $y === 0) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($this->userId);
			/** @var Folder $versionFolder */
			$versionFolder = $userFolder->getParent()->get('files_versions');
			$mimeType = $this->mimeTypeDetector->detectPath($file);
			$file = $versionFolder->get($file.'.v'.$version);

			/** @var File $file */
			$f = $this->previewManager->getPreview($file, $x, $y, true, IPreview::MODE_FILL, $mimeType);
			return new FileDisplayResponse($f, Http::STATUS_OK, ['Content-Type' => $f->getMimeType()]);
		} catch (NotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}
	}
}
