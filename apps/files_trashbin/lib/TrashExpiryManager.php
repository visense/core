<?php

/**
 * @author Piotr Mrowczynski piotr@owncloud.com
 *
 * @copyright Copyright (c) 2019, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Files_Trashbin;

use OCP\ILogger;

class TrashExpiryManager {

	/* @var Expiration */
	private $expiration;

	/* @var Quota */
	private $quota;

	/* @var ILogger */
	private $logger;

	public function __construct(Expiration $expiration,
								Quota $quota,
								Ilogger $logger) {
		$this->logger = $logger;
		$this->expiration = $expiration;
		$this->quota = $quota;
	}

	public function expiryEnabled() {
		return $this->expiration->isEnabled();
	}

	public function expiryByRetentionEnabled() {
		return $this->expiration->getMaxAgeAsTimestamp() !== false;
	}

	/**
	 * Expire trashbin using retention setting.
	 *
	 * @param string $uid
	 */
	public function expireTrashByRetention(string $uid) {
		// get trashbin files for this user and sort ascending by mtime
		// sorting will allow us to stop expiring early
		$userTrashbinContent = Helper::getTrashFiles('/', $uid, 'mtime', false);
		foreach ($userTrashbinContent as $key => $trashbinEntry) {
			if ($this->expiration->isExpired($trashbinEntry->getMtime())) {
				Trashbin::delete($trashbinEntry->getName(), $uid, $trashbinEntry->getMtime());
				$this->logger->info(
					'Remove "' . $trashbinEntry->getName() . '" from "' . $uid . '" trashbin because it exceeds max retention obligation term.',
					['app' => 'files_trashbin']
				);
			} else {
				break;
			}
		}
	}

	/**
	 * Expire trashbin. Retention predicate will be applied first, and if quota
	 * not reached, trashin will be cleaned by space predicate.
	 *
	 * @param string $uid
	 */
	public function expireTrash(string $uid) {
		$trashBinSize = Trashbin::getTrashbinSize($uid);
		$availableSpace = $this->quota->calculateFreeSpace($trashBinSize, $uid);

		// delete all files older then $retention_obligation
		// get trashbin files for this user and sort ascending by mtime
		// sorting will allow us to stop expiring early
		$remainingUserTrashbinContent = Helper::getTrashFiles('/', $uid, 'mtime', false);
		foreach ($remainingUserTrashbinContent as $key => $trashbinEntry) {
			if ($this->expiration->isExpired($trashbinEntry->getMtime())) {
				$availableSpace += Trashbin::delete($trashbinEntry->getName(), $uid, $trashbinEntry->getMtime());
				$this->logger->info(
					'Remove "' . $trashbinEntry->getName() . '" from trashbin because it exceeds max retention obligation term.',
					['app' => 'files_trashbin']
				);

				// unset the deleted file as it was already processed
				unset($remainingUserTrashbinContent[$key]);
			} else {
				break;
			}
		}

		// if space is still negative, purge remaining files
		if ($availableSpace < 0) {
			foreach ($remainingUserTrashbinContent as $key => $trashbinEntry) {
				if ($availableSpace < 0 && $this->expiration->isExpired($trashbinEntry->getMtime(), true)) {
					$deletedSize = Trashbin::delete($trashbinEntry->getName(), $uid, $trashbinEntry->getMtime());
					$message = \sprintf(
						'remove "%s" (%dB) to meet the limit of trash bin size (%d%% of available quota)',
						$trashbinEntry->getMtime(),
						$deletedSize,
						$this->quota->getPurgeLimit()
					);

					$this->logger->info(
						$message,
						['app' => 'files_trashbin']
					);

					$availableSpace += $deletedSize;
				} else {
					break;
				}
			}
		}
	}
}
