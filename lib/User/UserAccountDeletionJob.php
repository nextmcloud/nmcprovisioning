<?php

namespace OCA\NextMagentaCloudProvisioning\User;

use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;

use OCP\ILogger;

//use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;

//class SlupCircuitBootJob extends QueuedJob {
class UserAccountDeletionJob extends TimedJob {
	public const CIRCUIT_BOOT_DELAY = 300;

	/** @var ILogger */
	private $logger;

	/** @var IConfig */
	private $config;

	/** @var UserQueries */
	private $userQueries;

	/** @var IUserManager */
	private $userManager;


	public function __construct(ITimeFactory $timeFactory,
		ILogger $logger,
		IConfig $config,
		UserQueries $userQueries,
		IUserManager $userManager) {
		parent::__construct($timeFactory);
		$this->logger = $logger; // this is inconsistent with TimedJob
		$this->config = $config;
		$this->userQueries = $userQueries;
		$this->userManager = $userManager;

		//$destTimeString = $this->config->getAppValue('nmcprovisioning', 'deletionjobtime', '04:00:00');
		//$destTime = new \DateTime($destTimeString);

		//$diff = $destTime->getTimestamp() - $this->getLastRun();
		// negative diff means that the lastRun date lies before the plan date today, so run today
		// otherwise tomorrow
		$this->setInterval(3 * 60 * 60);
	}

	// Method re-declared public for unittest purpose
	public function getInterval() : int {
		return $this->interval;
	}

	public function run($arguments) {
		$this->logger->info("User account deletion job started");

		// TODO: chunk deletion loop with offset, limit
		// if the set of deletion users is too big
		$refTime = new \DateTime(); // NOW
		$expiredUids = $this->userQueries->findDeletions($refTime);
		$this->logger->info(\count($expiredUids) . " withdrawn user with expired retention period.");
		foreach ($expiredUids as $uid) {
			try {
				$user = $this->userManager->get($uid);
				$this->logger->info("Deleting " . $uid);
				$user->delete();
				$this->logger->info(\count($uid) . " deleted");
			} catch (\Throwable $e) {
				$this->logger->logException($e, [
					'message' => $uid . ': Deletion failed with ' . $e->getMessage(),
					'level' => ILogger::ERROR,
					'app' => 'nmcprovisioning'
				]);
			}
		}

		$this->logger->info("User account deletion job ended");
	}
}
