<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCA\NextMagentaCloudProvisioning\User\UserAccountDeletionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserAccountDeletionJobTest extends TestCase {
	private const UID = '120042010000000004200001';

	private $app;
	private $timeFactory;
	private $userQueries;
	private $userManager;
	private $job;

	public function setUp(): void {
		parent::setUp();

		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(2_000_000_000);

		$this->userQueries = $this->createMock(UserQueries::class);
		$this->userManager = $this->getMockForAbstractClass(IUserManager::class);

		$this->job = new UserAccountDeletionJob($this->timeFactory, $this->app->getContainer()->get(LoggerInterface::class), $this->userQueries, $this->userManager);
	}

	public function testJobRunNone(): void {
		$this->userQueries->expects($this->once())
			->method('findDeletions')
			->willReturn([]);

		$this->userManager->expects($this->never())
			->method('get');

		$this->job->run(null);
	}

	public function testValidDisabledUserIsDeleted(): void {
		$state = $this->validState();

		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->once())
			->method('getDeletionState')
			->with(self::UID)
			->willReturn($state);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$user->expects($this->once())
			->method('delete')
			->willReturn(true);

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn($user);

		$this->userQueries->expects($this->once())
			->method('deleteUserPreferenceById')
			->with(self::UID);

		$this->job->run(null);
	}

	public function testEnabledUserIsNeverDeleted(): void {
		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->never())
			->method('getDeletionState');

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->expects($this->never())->method('delete');

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn($user);

		$this->userQueries->expects($this->once())
			->method('deleteDeletionPreferencesById')
			->with(self::UID);

		$this->job->run(null);
	}

	public function testLegacyDeletionMarkerWithoutMetadataIsProcessed(): void {
		$state = $this->validState();
		$state['withdrawal'] = null;
		$state['lastAccountChange'] = null;

		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->once())
			->method('getDeletionState')
			->with(self::UID)
			->willReturn($state);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$user->expects($this->once())
			->method('delete')
			->willReturn(true);

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn($user);

		$this->userQueries->expects($this->never())
			->method('deleteDeletionPreferencesById');

		$this->userQueries->expects($this->once())
			->method('deleteUserPreferenceById')
			->with(self::UID);

		$this->job->run(null);
	}

	public function testIncompleteNewDeletionMarkerIsDisarmed(): void {
		$state = $this->validState();
		$state['withdrawal'] = null;

		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->once())
			->method('getDeletionState')
			->with(self::UID)
			->willReturn($state);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$user->expects($this->never())->method('delete');

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn($user);

		$this->userQueries->expects($this->once())
			->method('deleteDeletionPreferencesById')
			->with(self::UID);

		$this->job->run(null);
	}

	public function testNewerAccountStateDisarmsDeletion(): void {
		$state = $this->validState();
		$state['lastAccountChange'] = new \DateTimeImmutable('2033-05-18T02:00:00+00:00');

		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->once())
			->method('getDeletionState')
			->with(self::UID)
			->willReturn($state);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$user->expects($this->never())->method('delete');

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn($user);

		$this->userQueries->expects($this->once())
			->method('deleteDeletionPreferencesById')
			->with(self::UID);

		$this->job->run(null);
	}

	public function testDeletionCancelledBeforeDeleteIsSkipped(): void {
		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->once())
			->method('getDeletionState')
			->with(self::UID)
			->willReturn(null);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$user->expects($this->never())->method('delete');

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn($user);

		$this->userQueries->expects($this->never())
			->method('deleteUserPreferenceById');

		$this->job->run(null);
	}

	public function testDeleteFailureIsPostponedAndPreferencesAreKept(): void {
		$state = $this->validState();

		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->exactly(2))
			->method('getDeletionState')
			->with(self::UID)
			->willReturn($state);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('isEnabled')->willReturn(false);
		$user->expects($this->once())
			->method('delete')
			->willReturn(false);

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn($user);

		$this->userQueries->expects($this->never())
			->method('deleteUserPreferenceById');

		$this->userQueries->expects($this->once())
			->method('postponeDeletion')
			->with(
				self::UID,
				$this->callback(static function (\DateTimeInterface $retryAt): bool {
					return $retryAt->getTimestamp() === 2_000_003_600;
				})
			);

		$this->job->run(null);
	}

	public function testMissingUserCleansOrphanedPreferences(): void {
		$this->userQueries->expects($this->exactly(2))
			->method('findDeletions')
			->willReturnOnConsecutiveCalls([self::UID], []);

		$this->userQueries->expects($this->never())
			->method('getDeletionState');

		$this->userManager->expects($this->once())
			->method('get')
			->with(self::UID)
			->willReturn(null);

		$this->userQueries->expects($this->once())
			->method('deleteUserPreferenceById')
			->with(self::UID);

		$this->job->run(null);
	}

	private function validState(): array {
		return [
			'deletion' => new \DateTimeImmutable('2033-05-18T01:00:00+00:00'),
			'withdrawal' => new \DateTimeImmutable('2033-03-19T01:00:00+00:00'),
			'lastAccountChange' => new \DateTimeImmutable('2033-03-19T01:00:00+00:00'),
		];
	}
}
