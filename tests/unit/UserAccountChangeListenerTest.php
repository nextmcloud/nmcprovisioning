<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCA\NextMagentaCloudProvisioning\Event\UserAccountChangeListener;
use OCA\NextMagentaCloudProvisioning\Rules\UserAccountRules;
use OCA\NextMagentaCloudProvisioning\User\NmcUserService;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Event\UserAccountChangeEvent;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserAccountChangeListenerTest extends TestCase {
	private const UID = '12004901000000000XXXXXXX';

	private $app;
	private $config;
	private $logger;
	private $userManager;
	private $oidcProviderMapper;
	private $userService;
	private $accountRules;
	private $listener;

	public function setUp(): void {
		parent::setUp();

		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->config = $this->getMockForAbstractClass(IConfig::class);
		$this->logger = $this->app->getContainer()->get(LoggerInterface::class);
		$this->userManager = $this->getMockForAbstractClass(IUserManager::class);
		$this->oidcProviderMapper = $this->app
			->getContainer()
			->get(ProviderMapper::class);

		$this->userService = $this->getMockBuilder(NmcUserService::class)
			->setConstructorArgs([
				$this->app->getContainer()->get(IUserManager::class),
				$this->app->getContainer()->get(IAccountManager::class),
				$this->logger,
				$this->config,
				$this->app->getContainer()->get(UserMapper::class),
				$this->app->getContainer()->get(ProviderMapper::class),
				$this->app->getContainer()->get(IGroupManager::class),
			])
			->onlyMethods([
				'create',
				'update',
				'userExists',
				'markDeletion',
				'unmarkDeletion',
				'isStaleAccountChange',
				'recordAccountChangeTime',
			])
			->getMock();

		$this->accountRules = new UserAccountRules($this->config, $this->logger, $this->userService, $this->userManager, $this->oidcProviderMapper);
		$this->listener = new UserAccountChangeListener($this->logger, $this->accountRules);

		$this->config->method('getSystemValue')
			->willReturn([
				'slup_test_account_check' => true,
				'slup_test_account_name' => '-test',
				'slup_test_account_explode' => '@',
			]);

		$this->config->method('getAppValue')
			->willReturnCallback(
				static function ($app, $key, $default = '') {
					$values = [
						'userwithdrawurl' => 'https://cloud.telekom-dienste.de',
						'userpreserveurl' => 'https://telekom.example.com/',
						'useraccessurl' => 'https://cloud.telekom-dienste.de/tarife',
						'userotturl' => 'https://cloud.telekom-dienste.de/ott',
					];

					return $values[$key] ?? $default;
				}
			);
	}

	public function testWithdrawNonExisting(): void {
		$this->userService->expects($this->once())
			->method('userExists')
			->willReturn(null);

		$event = $this->event(
			$this->claims([
				'urn:telekom.com:f556' => '0',
			])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertSame('No tariff no new account', $event->getResult()->getReason());
	}

	public function testExistingWithdrawalWithChangeTimeSchedulesDeletion(): void {
		$user = $this->existingUser(false);

		$this->userService->method('userExists')
			->willReturn($user);
		$this->userService->method('isStaleAccountChange')
			->willReturn(false);

		$expectedDeletion = new \DateTimeImmutable('2030-03-02T00:00:00+00:00');

		$this->userService->expects($this->once())
			->method('markDeletion')
			->willReturn($expectedDeletion);

		$this->userService->expects($this->once())
			->method('update')
			->with($user, 'jonny.gyros', 'jonny.gyros@ver.sul.t-online.de', '3 GB', false);

		$event = $this->event(
			$this->claims([
				'urn:telekom.com:f556' => '0',
				'changeTime' => '2030-01-01T00:00:00Z',
			])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertSame('Withdrawn', $event->getResult()->getReason());
	}

	public function testWithdrawalWithoutChangeTimeNeverSchedulesDeletion(): void {
		$user = $this->existingUser(false);

		$this->userService->method('userExists')
			->willReturn($user);

		$this->userService->expects($this->never())
			->method('markDeletion');

		$this->userService->expects($this->once())
			->method('unmarkDeletion')
			->with(self::UID);

		$this->userService->expects($this->once())
			->method('update')
			->with($user, 'jonny.gyros', 'jonny.gyros@ver.sul.t-online.de', '3 GB', false);

		$event = $this->event(
			$this->claims([
				'urn:telekom.com:f556' => '0',
				'auth_time' => 1637683330,
			])
		);

		$this->listener->handle($event);

		$this->assertSame('Withdrawn without valid changeTime - deletion not scheduled', $event->getResult()->getReason());
	}

	public function testMissingBookingClaimNeverSchedulesDeletion(): void {
		$user = $this->existingUser(false);

		$this->userService->method('userExists')
			->willReturn($user);

		$this->userService->expects($this->never())
			->method('markDeletion');

		$this->userService->expects($this->once())
			->method('unmarkDeletion')
			->with(self::UID);

		$event = $this->event(
			$this->claims([
				'changeTime' => '2030-01-01T00:00:00Z',
			])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertSame('Missing tariff claim - deletion not scheduled', $event->getResult()->getReason());
	}

	public function testStaleWithdrawalIsIgnored(): void {
		$user = $this->existingUser(true);

		$this->userService->method('userExists')
			->willReturn($user);
		$this->userService->method('isStaleAccountChange')
			->willReturn(true);

		$this->userService->expects($this->never())
			->method('markDeletion');
		$this->userService->expects($this->never())
			->method('update');

		$event = $this->event(
			$this->claims([
				'urn:telekom.com:f556' => '0',
				'changeTime' => '2030-01-01T00:00:00Z',
			])
		);

		$this->listener->handle($event);

		$this->assertTrue($event->getResult()->isAccessAllowed());
		$this->assertSame('Stale withdrawal event ignored', $event->getResult()->getReason());
	}

	public function testReactivationAlwaysCancelsDeletion(): void {
		$user = $this->existingUser(false);

		$this->userService->method('userExists')
			->willReturn($user);
		$this->userService->method('isStaleAccountChange')
			->willReturn(false);

		$this->userService->expects($this->once())
			->method('unmarkDeletion')
			->with(self::UID);

		$this->userService->expects($this->once())
			->method('update')
			->with($user, 'jonny.gyros', 'jonny.gyros@ver.sul.t-online.de', '3 GB', true);

		$event = $this->event(
			$this->claims([
				'urn:telekom.com:f556' => '1',
				'changeTime' => '2030-01-02T00:00:00Z',
			])
		);

		$this->listener->handle($event);

		$this->assertTrue($event->getResult()->isAccessAllowed());
		$this->assertSame('Updated', $event->getResult()->getReason());
	}

	public function testBookedButLockedStillCancelsDeletion(): void {
		$user = $this->existingUser(false);

		$this->userService->method('userExists')
			->willReturn($user);
		$this->userService->method('isStaleAccountChange')
			->willReturn(false);

		$this->userService->expects($this->once())
			->method('unmarkDeletion')
			->with(self::UID);

		$this->userService->expects($this->once())
			->method('update')
			->with($user, 'jonny.gyros', 'jonny.gyros@ver.sul.t-online.de', '3 GB', false);

		$event = $this->event(
			$this->claims([
				'urn:telekom.com:f556' => '1',
				'urn:telekom.com:s556' => '1',
				'changeTime' => '2030-01-02T00:00:00Z',
			])
		);

		$this->listener->handle($event);

		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertSame('Locked', $event->getResult()->getReason());
	}

	public function testPreserveRedirectIsKept(): void {
		$user = $this->existingUser(false);

		$this->userService->method('userExists')
			->willReturn($user);
		$this->userService->method('isStaleAccountChange')
			->willReturn(false);
		$this->userService->method('markDeletion')
			->willReturn(new \DateTimeImmutable('2030-03-02T00:00:00+00:00'));

		$event = $this->event(
			$this->claims([
				'urn:telekom.com:f556' => '0',
				'urn:telekom.com:f734' => '1',
				'changeTime' => '2030-01-01T00:00:00Z',
			])
		);

		$this->listener->handle($event);

		$this->assertSame(
			'https://telekom.example.com/',
			$event->getResult()->getRedirectUrl()
		);
	}

	private function existingUser(bool $enabled): IUser {
		$user = $this->getMockForAbstractClass(IUser::class);
		$user->method('getUID')->willReturn(self::UID);
		$user->method('isEnabled')->willReturn($enabled);

		return $user;
	}

	private function event(object $claims): UserAccountChangeEvent {
		return new UserAccountChangeEvent(self::UID, 'jonny.gyros', 'jonny.gyros@ver.sul.t-online.de', '3 GB', $claims);
	}

	private function claims(array $overrides = []): object {
		return (object)array_merge(
			[
				'urn:telekom.com:s556' => '0',
				'urn:telekom.com:usta' => '1',
				'urn:telekom.com:f734' => '0',
			],
			$overrides
		);
	}
}
