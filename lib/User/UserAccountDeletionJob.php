<?php

namespace OCA\NextMagentaCloudProvisioning\User;

use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\ILogger;
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

	public function __construct(ITimeFactory $timeFactory,
		LoggerInterface $logger,
		UserQueries $userQueries,
		IUserManager $userManager) {
		parent::__construct($timeFactory);
		$this->logger = $logger;
		$this->userQueries = $userQueries;
		$this->userManager = $userManager;
	}

	public function getInterval(): int {
		return $this->interval;
	}

	public function run($arguments) {
		$this->logger->info("User account deletion job started");
	
		$startTime = time(); // start time of job
		$maxExecutionTime = 10800; // max. job time (3 hours)
		$maxDeletionTimePerUser = 1800; // max. time per user (30 minutes)

		$refTime = new \DateTime(); // find deletions older than current time
		$limit = 10; // number of users per batch
		$offset = 0; // start offset
	
		while (time() - $startTime < $maxExecutionTime) {
			$expiredUids = $this->userQueries->findDeletions($refTime, $limit, $offset);
			
			if (empty($expiredUids)) {
				$this->logger->info("No more users to delete, exiting job.");
				break; // No more users to delete
			}
	
			$this->logger->info(\count($expiredUids) . " users found for deletion in this batch.");
	
			foreach ($expiredUids as $uid) {
				// cancel if the runtime has exceeded 3 hours
				if (time() - $startTime > $maxExecutionTime) {
					$this->logger->info("User account deletion job stopped after 3 hours.");
					return;
				}
	
				try {
					$user = $this->userManager->get($uid);
					if (!$user) {
						$this->logger->warning("User $uid not found, skipping.");
						continue;
					}
	
					$this->logger->info("Deleting " . $uid);
					$startDeletionTime = time(); // start time for this user
	
					// delete user
					$user->delete();
	
					// if deletion takes longer than 30 minutes, cancel

					if (time() - $startDeletionTime > $maxDeletionTimePerUser) {
						$this->logger->warning("User $uid deletion took too long, skipping.");
						continue;
					}
	
					$this->logger->info("User $uid deleted successfully.");
	
				} catch (\Throwable $e) {
					$this->logger->logException($e, [
						'message' => "Deletion failed for $uid: " . $e->getMessage(),
						'level' => ILogger::ERROR,
						'app' => 'nmcprovisioning'
					]);
					continue; // jump to the next user in case of errors
				}
			}
	
			$offset += $limit; // jump to next batch
		}
	
		$this->logger->info("User account deletion job ended");
	}
}
