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
			$state = $this->userQueries->getDeletionState($uid);

			if ($state === null) {
				$this->logger->warning("Skipping {$uid}, deletion entry no longer exists.");
				return;
			}

			if ($state['deletion'] > $this->now()) {
				$this->logger->warning("Skipping {$uid}, deletion not due yet: " . $state['deletion']->format(\DateTimeInterface::ATOM));
				return;
			}

			/*
			 * Old/partial deletion markers are deliberately not trusted.
			 * Supporting metadata is required before irreversible deletion.
			 */
			if ($state['withdrawal'] === null || $state['markedAt'] === null) {
				$this->logger->critical("Skipping {$uid}: incomplete deletion metadata. " . 'Deletion marker will be disarmed.');
				$this->userQueries->deleteDeletionPreferencesById($uid);
				return;
			}

			if ($state['lastAccountChange'] !== null && $state['lastAccountChange']->getTimestamp() > $state['withdrawal']->getTimestamp()) {
				$this->logger->critical("Skipping {$uid}: newer account state exists. " . 'Deletion marker will be disarmed.');
				$this->userQueries->deleteDeletionPreferencesById($uid);
				return;
			}

			$user = $this->userManager->get($uid);

			if ($user === null) {
				$this->logger->warning("User {$uid} not found, removing orphaned preferences.");
				$this->userQueries->deleteUserPreferenceById($uid);
				return;
			}

			/*
			 * Reactivation is authoritative in the safe direction. An enabled
			 * user must never be deleted by this background job.
			 */
			if ($user->isEnabled()) {
				$this->logger->critical("Skipping {$uid}: user is enabled while marked for deletion. " . 'Deletion marker will be disarmed.');
				$this->userQueries->deleteDeletionPreferencesById($uid);
				return;
			}

			$initialFingerprint = $this->fingerprint($state);

			/*
			 * Re-read deletion metadata after resolving the user and immediately
			 * before delete(). This preserves the current recheck behaviour and
			 * extends it to all deletion metadata.
			 */
			$currentState = $this->userQueries->getDeletionState($uid);

			if ($currentState === null) {
				$this->logger->warning("Skipping {$uid}: deletion was cancelled before delete().");
				return;
			}

			if (!hash_equals($initialFingerprint, $this->fingerprint($currentState))) {
				$this->logger->warning("Skipping {$uid}: deletion state changed before delete().");
				return;
			}

			if (
				$currentState['deletion'] > $this->now()
				|| $currentState['withdrawal'] === null
				|| $currentState['markedAt'] === null
				|| ($currentState['lastAccountChange'] !== null && $currentState['lastAccountChange']->getTimestamp() > $currentState['withdrawal']->getTimestamp())
			) {
				$this->logger->warning("Skipping {$uid}: final deletion validation failed.");
				return;
			}

			/*
			 * Re-fetch the user too. A reactivation may have happened while the
			 * deletion state was being checked.
			 */
			$user = $this->userManager->get($uid);

			if ($user === null) {
				$this->logger->warning("User {$uid} disappeared before delete(), cleaning preferences.");
				$this->userQueries->deleteUserPreferenceById($uid);
				return;
			}

			if ($user->isEnabled()) {
				$this->logger->critical("Skipping {$uid}: user was re-enabled before delete(). " . 'Deletion marker will be disarmed.');
				$this->userQueries->deleteDeletionPreferencesById($uid);
				return;
			}

			/*
			 * One final metadata read after the final user-state read also protects
			 * booked-but-locked reactivations: those may stay disabled while still
			 * cancelling the deletion marker.
			 */
			$finalState = $this->userQueries->getDeletionState($uid);

			if ($finalState === null || !hash_equals($initialFingerprint, $this->fingerprint($finalState))) {
				$this->logger->warning("Skipping {$uid}: deletion state changed during final validation.");
				return;
			}

			$this->logger->info("Deleting {$uid}, deletion due since " . $currentState['deletion']->format(\DateTimeInterface::ATOM));

			$startDeletionTime = $this->timeFactory->getTime();

			$user->delete();

			$duration = $this->timeFactory->getTime() - $startDeletionTime;

			/*
			 * The old code said "skipping" here even though delete() had already
			 * happened, which also skipped preference cleanup. A slow deletion
			 * is now only a warning; cleanup still runs.
			 */
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

	private function fingerprint(array $state): string {
		return implode(':', [
			(string)$state['deletion']->getTimestamp(),
			$state['withdrawal'] instanceof \DateTimeInterface ? (string)$state['withdrawal']->getTimestamp() : '-',
			$state['markedAt'] instanceof \DateTimeInterface ? (string)$state['markedAt']->getTimestamp() : '-',
			$state['lastAccountChange'] instanceof \DateTimeInterface ? (string)$state['lastAccountChange']->getTimestamp() : '-',
		]);
	}
}
