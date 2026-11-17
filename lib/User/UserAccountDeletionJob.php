<?php

namespace OCA\NextMagentaCloudProvisioning\User;

use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class UserAccountDeletionJob extends TimedJob {
	public const CIRCUIT_BOOT_DELAY = 300;

	private const MAX_EXECUTION_TIME = 10800;
	private const SLOW_DELETION_WARNING = 1800;
	private const RETRY_DELAY = 3600;
	private const BATCH_SIZE = 10;

	/** @var LoggerInterface */
	private $logger;

	/** @var UserQueries */
	private $userQueries;

	/** @var IUserManager */
	private $userManager;

	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(ITimeFactory $timeFactory, LoggerInterface $logger, UserQueries $userQueries, IUserManager $userManager) {
		parent::__construct($timeFactory);
		$this->timeFactory = $timeFactory;
		$this->logger = $logger;
		$this->userQueries = $userQueries;
		$this->userManager = $userManager;
	}

	public function getInterval(): int {
		return $this->interval;
	}

	public function run($arguments): void {
		$this->logger->info('User account deletion job started');

		$startTime = $this->timeFactory->getTime();

		while ($this->timeFactory->getTime() - $startTime < self::MAX_EXECUTION_TIME) {
			$refTime = $this->now();
			$expiredUids = $this->userQueries->findDeletions($refTime, self::BATCH_SIZE);

			if ($expiredUids === []) {
				$this->logger->info('No more users to delete, exiting job.');
				break;
			}

			$this->logger->info(\count($expiredUids) . ' users found for deletion in this batch.');

			foreach ($expiredUids as $uid) {
				if ($this->timeFactory->getTime() - $startTime >= self::MAX_EXECUTION_TIME) {
					$this->logger->info('User account deletion job stopped after 3 hours.');
					return;
				}

				$this->processUser($uid);
			}
		}

		$this->logger->info('User account deletion job ended');
	}

	private function processUser(string $uid): void {
		try {
			$user = $this->userManager->get($uid);

			if ($user === null) {
				$this->logger->warning("User {$uid} not found, removing orphaned preferences.");
				$this->userQueries->deleteUserPreferenceById($uid);
				return;
			}

			/*
			 * Reactivation is authoritative in the safe direction. This is not a
			 * concurrency guarantee because IUserManager may cache in-process, but it
			 * still protects against an already enabled account.
			 */
			if ($user->isEnabled()) {
				$this->logger->critical("Skipping {$uid}: user is enabled while marked for deletion. " . 'Deletion marker will be disarmed.');
				$this->userQueries->deleteDeletionPreferencesById($uid);
				return;
			}

			/*
			 * Validate the deletion decision once directly against the preferences DB
			 * immediately before delete(). getDeletionState() performs a single query.
			 */
			$state = $this->userQueries->getDeletionState($uid);

			if ($state === null) {
				$this->logger->warning("Skipping {$uid}: deletion was cancelled before delete().");
				return;
			}

			if ($state['deletion'] > $this->now()) {
				$this->logger->warning("Skipping {$uid}, deletion not due yet: " . $state['deletion']->format(\DateTimeInterface::ATOM));
				return;
			}

			if ($state['withdrawal'] === null) {
				/*
				 * Deletion markers created before this hardening PR contain only the
				 * deletion timestamp. Keep processing those markers with the previous
				 * semantics instead of disarming all pending production deletions.
				 *
				 * If last_account_change exists, however, this is an incomplete state
				 * written by the new code and must fail safe.
				 */
				if ($state['lastAccountChange'] !== null) {
					$this->logger->critical("Skipping {$uid}: incomplete deletion metadata. " . 'Deletion marker will be disarmed.');
					$this->userQueries->deleteDeletionPreferencesById($uid);
					return;
				}

				$this->logger->warning("Processing {$uid} with a legacy deletion marker without withdrawal metadata.");
			} elseif (
				$state['lastAccountChange'] !== null
				&& $state['lastAccountChange']->getTimestamp() > $state['withdrawal']->getTimestamp()
			) {
				$this->logger->critical("Skipping {$uid}: newer account state exists. " . 'Deletion marker will be disarmed.');
				$this->userQueries->deleteDeletionPreferencesById($uid);
				return;
			}

			$this->logger->info("Deleting {$uid}, deletion due since " . $state['deletion']->format(\DateTimeInterface::ATOM));

			$startDeletionTime = $this->timeFactory->getTime();

			if (!$user->delete()) {
				throw new \RuntimeException("User deletion returned false for {$uid}");
			}

			$duration = $this->timeFactory->getTime() - $startDeletionTime;

			if ($duration > self::SLOW_DELETION_WARNING) {
				$this->logger->warning("User {$uid} deletion took longer than 30 minutes " . "({$duration} seconds).");
			}

			$this->userQueries->deleteUserPreferenceById($uid);

			$this->logger->info("User {$uid} deleted successfully and preferences cleaned up.");
		} catch (\Throwable $e) {
			$this->logger->error(
				"Deletion failed for {$uid}: " . $e->getMessage(),
				[
					'exception' => $e,
					'app' => 'nmcprovisioning',
				]
			);

			/*
			 * Avoid selecting the same broken account over and over during the
			 * same three-hour run. If it still has a deletion marker, retry it
			 * later.
			 */
			try {
				$currentState = $this->userQueries->getDeletionState($uid);
				if ($currentState !== null) {
					$this->userQueries->postponeDeletion($uid, $this->now()->modify('+' . self::RETRY_DELAY . ' seconds'));
				}
			} catch (\Throwable $retryException) {
				$this->logger->error(
					"Failed to postpone deletion for {$uid}: " . $retryException->getMessage(),
					[
						'exception' => $retryException,
						'app' => 'nmcprovisioning',
					]
				);
			}
		}
	}

	private function now(): \DateTimeImmutable {
		return (new \DateTimeImmutable())->setTimestamp($this->timeFactory->getTime());
	}

}
