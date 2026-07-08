<?php

namespace OCA\NextMagentaCloudProvisioning\User;

use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class UserAccountDeletionJob extends TimedJob {
	public const CIRCUIT_BOOT_DELAY = 300;

	/** @var LoggerInterface */
	private $logger;

	/** @var UserQueries */
	private $userQueries;

	/** @var IUserManager */
	private $userManager;

	public function __construct(
		ITimeFactory $timeFactory,
		LoggerInterface $logger,
		UserQueries $userQueries,
		IUserManager $userManager
	) {
		parent::__construct($timeFactory);
		$this->logger = $logger;
		$this->userQueries = $userQueries;
		$this->userManager = $userManager;
	}

	public function getInterval(): int {
		return $this->interval;
	}

	public function run($arguments) {
		$this->logger->info('User account deletion job started');

		$startTime = time();
		$maxExecutionTime = 10800; // 3 hours
		$maxDeletionTimePerUser = 1800; // 30 minutes

		$limit = 10;

		while (time() - $startTime < $maxExecutionTime) {
			$refTime = new \DateTime();
			$expiredUids = $this->userQueries->findDeletions($refTime, $limit);

			if (empty($expiredUids)) {
				$this->logger->info('No more users to delete, exiting job.');
				break;
			}

			$this->logger->info(\count($expiredUids) . ' users found for deletion in this batch.');

			foreach ($expiredUids as $uid) {
				if (time() - $startTime > $maxExecutionTime) {
					$this->logger->info('User account deletion job stopped after 3 hours.');
					return;
				}

				try {
					$deletionDate = $this->userQueries->getDeletionDateTime($uid);

					if ($deletionDate === null) {
						$this->logger->warning("Skipping $uid, deletion entry no longer exists.");
						continue;
					}

					$now = new \DateTime();
					if ($deletionDate > $now) {
						$this->logger->warning(
							"Skipping $uid, deletion not due yet: " . $deletionDate->format(\DateTimeInterface::ATOM)
						);
						continue;
					}

					$user = $this->userManager->get($uid);
					if (!$user) {
						$this->logger->warning("User $uid not found, removing user preferences and cleaning up.");
						$this->userQueries->deleteUserPreferenceById($uid);
						continue;
					}

					$this->logger->info("Deleting $uid, deletion due since " . $deletionDate->format(\DateTimeInterface::ATOM));

					$startDeletionTime = time();

					$user->delete();

					if (time() - $startDeletionTime > $maxDeletionTimePerUser) {
						$this->logger->warning("User $uid deletion took longer than 30 minutes, skipping.");
						continue;
					}

					$this->userQueries->deleteUserPreferenceById($uid);

					$this->logger->info("User $uid deleted successfully and preferences cleaned up.");
				} catch (\Throwable $e) {
					$this->logger->error("Deletion failed for $uid: " . $e->getMessage(), [
						'exception' => $e,
						'app' => 'nmcprovisioning',
					]);

					continue;
				}
			}
		}

		$this->logger->info('User account deletion job ended');
	}
}
