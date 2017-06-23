<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christopher Schäpers <kondou@ts.unde.re>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Tom Needham <tom@owncloud.com>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, snapshot 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, snapshot 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Snapshots;

use OC\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

class DownloadResponse extends Response implements ICallbackResponse {

	/** @var resource */
	private $stream;

	/**
	 * FileDisplayResponse constructor.
	 *
	 * @param resource $stream
	 * @param int $length
	 * @param string $name
	 * @param int $lastModified
	 * @param string $mimeType
	 * @param int $statusCode
	 * @param array $headers
	 */
	public function __construct($stream, $length, $name, $lastModified, $mimeType, $statusCode = Http::STATUS_OK,
	                            $headers = []) {
		$this->stream = $stream;
		$this->setStatus($statusCode);
		$this->setHeaders(array_merge($this->getHeaders(), $headers));
		$this->addHeader('Content-Length', $length);
		$this->addHeader('Content-Type', $mimeType);
		$this->addHeader('Content-Disposition', 'download; filename="' . rawurldecode($name) . '"');

		$lastModifiedDate = new \DateTime();
		$lastModifiedDate->setTimestamp($lastModified);
		$this->setLastModified($lastModifiedDate);
	}

	/**
	 * @param IOutput $output
	 * @since 11.0.0
	 */
	public function callback(IOutput $output) {
		if ($output->getHttpResponseCode() !== Http::STATUS_NOT_MODIFIED) {
			fpassthru($this->stream);
		}
	}
}
