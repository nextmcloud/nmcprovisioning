<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCA\NextMagentaCloudProvisioning\Rules\UserAccountRules;
use OCA\NextMagentaCloudProvisioning\User\NmcUserService;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\UserMapper;
use OCP\Accounts\IAccountManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserWithdrawTest extends TestCase {
	private $app;
	private $config;
	private $logger;
	private $userManager;
	private $oidcProviderMapper;
	private $userService;
	private $accountService;
	private array $userValues = [];
	private array $appValues = [];

	public function setUp(): void {
		parent::setUp();

		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->config = $this->getMockForAbstractClass(IConfig::class);
		$this->logger = $this->app->getContainer()->get(LoggerInterface::class);
		$this->userManager = $this->getMockForAbstractClass(IUserManager::class);
		$this->oidcProviderMapper = $this->app->getContainer()->get(ProviderMapper::class);

		$this->configureInMemoryUserValues();

		$this->appValues = [
			'userretention' => 'P60D',
			'userdeletionsafetydelay' => 'PT0S',
		];

		$this->config->method('getAppValue')
			->willReturnCallback(function ($app, $key, $default = '') { return $this->appValues[$key] ?? $default; });

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
			->onlyMethods(['create', 'update'])
			->getMock();

		$this->accountService = new UserAccountRules($this->config, $this->logger, $this->userService, $this->userManager, $this->oidcProviderMapper);
	}

	public function testWithdrawalDateUsesChangeTime(): void {
		$claims = new \stdClass();
		$claims->changeTime = '2021-11-18T08:11:09Z';

		$withdrawalTime = $this->accountService->withdrawDate($claims);

		$this->assertNotNull($withdrawalTime);
		$this->assertSame('2021-11-18T08:11:09+00:00', $withdrawalTime->format(\DateTimeInterface::ATOM));
	}

	public function testWithdrawalDateDoesNotFallbackToAuthTime(): void {
		$claims = new \stdClass();
		$claims->auth_time = 1637683330;

		$this->assertNull($this->accountService->withdrawDate($claims));
	}

	public function testWithdrawalDateWithoutTimestampIsNull(): void {
		$this->assertNull($this->accountService->withdrawDate(new \stdClass()));
	}

	public function testMarkDeletionDefaultRetention(): void {
		$userId = '12004901000000000XXXXXXX';
		$withdrawal = new \DateTimeImmutable('2030-08-03T16:05:06+00:00');
		$expected = $withdrawal->add(new \DateInterval('P60D'));

		$deletionDate = $this->userService->markDeletion($userId, $withdrawal);

		$this->assertNotNull($deletionDate);
		$this->assertSame($expected->getTimestamp(), $deletionDate->getTimestamp());

		$this->assertSame($withdrawal->getTimestamp(), (int)$this->userValues[$userId]['withdrawal']);
		$this->assertSame($expected->getTimestamp(), (int)$this->userValues[$userId]['deletion']);
		$this->assertSame($withdrawal->getTimestamp(), (int)$this->userValues[$userId]['last_account_change']);
	}

	public function testOldWithdrawalGetsLocalSafetyDelay(): void {
		$userId = '12004901000000000XXXXXXX';
		$withdrawal = new \DateTimeImmutable('2021-08-03T16:05:06+00:00');

		$this->appValues['userdeletionsafetydelay'] = 'P1D';

		$before = time();
		$deletionDate = $this->userService->markDeletion($userId, $withdrawal);
		$after = time();

		$this->assertNotNull($deletionDate);
		$this->assertGreaterThanOrEqual($before + 86400, $deletionDate->getTimestamp());
		$this->assertLessThanOrEqual($after + 86401, $deletionDate->getTimestamp());
	}

	public function testStaleWithdrawalIsRejected(): void {
		$userId = '12004901000000000XXXXXXX';

		$this->userValues[$userId]['last_account_change'] = (new \DateTimeImmutable('2030-08-04T00:00:00+00:00'))
				->getTimestamp();

		$result = $this->userService->markDeletion($userId, new \DateTimeImmutable('2030-08-03T00:00:00+00:00'));

		$this->assertNull($result);
		$this->assertArrayNotHasKey('deletion', $this->userValues[$userId]);
	}

	public function testUnmarkDeletionRemovesDeletionMetadataButKeepsOrdering(): void {
		$userId = '12004901000000000XXXXXXX';
		$this->userValues[$userId] = [
			'deletion' => 10,
			'withdrawal' => 9,
			'deletion_marked_at' => 8,
			'last_account_change' => 9,
		];

		$this->userService->unmarkDeletion($userId);

		$this->assertArrayNotHasKey('deletion', $this->userValues[$userId]);
		$this->assertArrayNotHasKey('withdrawal', $this->userValues[$userId]);
		$this->assertArrayNotHasKey('deletion_marked_at', $this->userValues[$userId]);
		$this->assertSame(9, $this->userValues[$userId]['last_account_change']);
	}

	private function configureInMemoryUserValues(): void {
		$this->config->method('getUserValue')
			->willReturnCallback(function ($userId, $appId, $key, $default = null) { return $this->userValues[$userId][$key] ?? $default; });

		$this->config->method('setUserValue')
			->willReturnCallback(function ($userId, $appId, $key, $value): void { $this->userValues[$userId][$key] = $value; });

		$this->config->method('deleteUserValue')
			->willReturnCallback(function ($userId, $appId, $key): void { unset($this->userValues[$userId][$key]); });
	}
}
