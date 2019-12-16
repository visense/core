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

use OCA\Files_Trashbin\Command\Expire;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUserManager;

class TrashExpiryManager {

	/* @var Expiration */
	private $expiration;

	/* @var Quota */
	private $quota;

	/* @var ILogger */
	private $logger;

	public function __construct(IUserManager $userManager,
								IConfig $config,
								ITimeFactory $timeFactory,
								Ilogger $logger) {
		$this->logger = $logger;
		$this->expiration = new Expiration(
			$config,
			$timeFactory
		);
		$this->quota = new Quota(
			$userManager,
			$config
		);
	}

	public function expiryEnabled() {
		return $this->expiration->isEnabled() !== false;
	}

	public function expiryByRetentionEnabled() {
		return $this->expiration->getMaxAgeAsTimestamp() !== false;
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
			$timestamp = $trashbinEntry['mtime'];
			$filename = $trashbinEntry['name'];
			if ($this->expiration->isExpired($timestamp)) {
			//if (true) {
				$availableSpace += Trashbin::delete($filename, $uid, $timestamp);
				$this->logger->info(
					'Remove "' . $filename . '" from trashbin because it exceeds max retention obligation term.',
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
				if ($availableSpace < 0 && $this->expiration->isExpired($trashbinEntry['mtime'], true)) {
					$tmp = Trashbin::delete($trashbinEntry['name'], $uid, $trashbinEntry['mtime']);
					$message = \sprintf(
						'remove "%s" (%dB) to meet the limit of trash bin size (%d%% of available quota)',
						$trashbinEntry['name'],
						$tmp,
						$this->quota->getPurgeLimit()
					);

					$this->logger->info(
						$message,
						['app' => 'files_trashbin']
					);

					$availableSpace += $tmp;
				} else {
					break;
				}
			}
		}
	}

	/**
	 * Expire trashbin using retention setting.
	 *
	 * @param string $uid
	 */
	public function expireTrashByRetention(string $uid) {
		$count = 0;
		$size = 0;
		$scheduledForDeletion = [];

		// get trashbin files for this user and sort ascending by mtime
		// sorting will allow us to stop expiring early
		$userTrashbinContent = Helper::getTrashFiles('/', $uid, 'mtime', false);
		foreach ($userTrashbinContent as $key => $trashbinEntry) {
			$filename = $trashbinEntry['name'];
			$timestamp = $trashbinEntry['mtime'];
			if ($this->expiration->isExpired($timestamp)) {
				$scheduledForDeletion[] = $filename . '.' .$timestamp;
				unset($userTrashbinContent[$key]);
			} else {
				break;
			}
		}
		unset($userTrashbinContent);

		// do actual delete
		foreach ($scheduledForDeletion as $key => $fileToDelete) {
			$fileToDeleteParts = explode('.', $fileToDelete);
			$count++;
			$size += Trashbin::delete($fileToDeleteParts[0], $uid, $fileToDeleteParts[1]);
			$this->logger->info(
				'Remove "' . $fileToDeleteParts[0] . '" from "' . $uid . '" trashbin because it exceeds max retention obligation term.',
				['app' => 'files_trashbin']
			);
			unset($scheduledForDeletion[$key]);
		}

		return [$size, $count];
	}

	/**
	 * Expire trashbin using retention setting.
	 *
	 * @param string $uid
	 */
	public function scheduleExpiry(string $uid) {
		if ($this->expiryEnabled()) {
			\OC::$server->getCommandBus()->push(
				new Expire($uid)
			);
			return true;
		}
		return false;
	}

}
