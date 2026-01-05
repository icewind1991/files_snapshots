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

namespace OCA\Files_Snapshots\Versions;

use OCA\Files_Versions\Versions\IVersion;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\ForbiddenException;
use OCP\Preview\IVersionedPreviewFile;

class SnapshotPreviewFile implements File, IVersionedPreviewFile {
	/**
	 * @param callable(): resource $contentProvider
	 */
	public function __construct(
		private FileInfo $sourceFile,
		private $contentProvider,
		private IVersion $version,
	) {
	}

	public function getContent(): string {
		return (string)stream_get_contents(($this->contentProvider)());
	}

	public function putContent($data) {
		throw new ForbiddenException('Preview files are read only', false);
	}

	public function fopen($mode) {
		if ($mode === 'r' || $mode === 'rb') {
			return ($this->contentProvider)();
		} else {
			throw new ForbiddenException('Preview files are read only', false);
		}
	}

	public function hash($type, $raw = false) {
		throw new \Exception('not implemented');
	}

	public function getChecksum() {
		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function getMtime() {
		return $this->version->getTimestamp();
	}

	/**
	 * @inheritDoc
	 */
	public function getMimetype(): string {
		return $this->sourceFile->getMimeType();
	}

	/**
	 * @inheritDoc
	 */
	public function getMimePart() {
		return $this->sourceFile->getMimePart();
	}

	/**
	 * @inheritDoc
	 */
	public function isEncrypted() {
		return $this->sourceFile->isEncrypted();
	}

	/**
	 * @inheritDoc
	 */
	public function getType() {
		return $this->sourceFile->getType();
	}

	/**
	 * @inheritDoc
	 */
	public function isCreatable() {
		return $this->sourceFile->isCreatable();
	}

	/**
	 * @inheritDoc
	 */
	public function isShared() {
		return $this->sourceFile->isShared();
	}

	/**
	 * @inheritDoc
	 */
	public function isMounted() {
		return $this->sourceFile->isMounted();
	}

	/**
	 * @inheritDoc
	 */
	public function getMountPoint() {
		return $this->sourceFile->getMountPoint();
	}

	/**
	 * @inheritDoc
	 */
	public function getOwner() {
		return $this->sourceFile->getOwner();
	}

	/**
	 * @inheritDoc
	 */
	public function getExtension(): string {
		return $this->sourceFile->getExtension();
	}

	/**
	 * @inheritDoc
	 */
	public function getPreviewVersion(): string {
		return (string)$this->version->getRevisionId();
	}

	/**
	 * @inheritDoc
	 */
	public function move($targetPath) {
		throw new ForbiddenException('Preview files are read only', false);
	}

	/**
	 * @inheritDoc
	 */
	public function delete() {
		throw new ForbiddenException('Preview files are read only', false);
	}

	/**
	 * @inheritDoc
	 */
	public function copy($targetPath) {
		throw new ForbiddenException('Preview files are read only', false);
	}

	/**
	 * @inheritDoc
	 */
	public function touch($mtime = null) {
		throw new ForbiddenException('Preview files are read only', false);
	}

	/**
	 * @inheritDoc
	 */
	public function getStorage() {
		return $this->sourceFile->getStorage();
	}

	/**
	 * @inheritDoc
	 */
	public function getPath() {
		return $this->sourceFile->getPath();
	}

	/**
	 * @inheritDoc
	 */
	public function getInternalPath() {
		return $this->sourceFile->getInternalPath();
	}

	/**
	 * @inheritDoc
	 */
	public function getId() {
		$id = $this->sourceFile->getId();
		if ($id === null) {
			throw new \Exception('invalid source file');
		} else {
			return $id;
		}
	}

	/**
	 * @inheritDoc
	 */
	public function stat() {
		return [
			'mtime' => $this->getMtime(),
			'size' => $this->getSize()
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getSize($includeMounts = true) {
		return $this->sourceFile->getSize();
	}

	/**
	 * @inheritDoc
	 */
	public function getEtag() {
		return (string)$this->version->getRevisionId();
	}

	/**
	 * @inheritDoc
	 */
	public function getPermissions() {
		return $this->sourceFile->getPermissions();
	}

	/**
	 * @inheritDoc
	 */
	public function isReadable() {
		return $this->sourceFile->isReadable();
	}

	/**
	 * @inheritDoc
	 */
	public function isUpdateable() {
		return $this->sourceFile->isUpdateable();
	}

	/**
	 * @inheritDoc
	 */
	public function isDeletable() {
		return $this->sourceFile->isDeletable();
	}

	/**
	 * @inheritDoc
	 */
	public function isShareable() {
		return $this->sourceFile->isShareable();
	}

	/**
	 * @inheritDoc
	 */
	public function getParent() {
		if ($this->sourceFile instanceof File) {
			return $this->sourceFile->getParent();
		} else {
			throw new \Exception('invalid source file');
		}
	}

	/**
	 * @inheritDoc
	 */
	public function getName() {
		return $this->sourceFile->getName();
	}

	/**
	 * @inheritDoc
	 */
	public function lock($type) {
		// noop
	}

	/**
	 * @inheritDoc
	 */
	public function changeLock($targetType) {
		// noop
	}

	/**
	 * @inheritDoc
	 */
	public function unlock($type) {
		// noop
	}

	/**
	 * @inheritDoc
	 */
	public function getCreationTime(): int {
		return 0;
	}

	/**
	 * @inheritDoc
	 */
	public function getUploadTime(): int {
		return 0;
	}

	public function getParentId(): int {
		return $this->getParent()->getId();
	}

	public function getMetadata(): array {
		return [];
	}
}
