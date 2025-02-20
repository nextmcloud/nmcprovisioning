<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCA\NextMagentaCloudProvisioning\User\UserAccountDeletionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserAccountDeletionJobTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->config = $this->getMockForAbstractClass(IConfig::class);
		$this->userQueries = $this->createMock(UserQueries::class);
		$this->userManager = $this->getMockForAbstractClass(IUserManager::class);
		$this->job = new UserAccountDeletionJob($this->app->getContainer()->get(ITimeFactory::class),
			$this->app->getContainer()->get(LoggerInterface::class),
			$this->config,
			$this->userQueries,
			$this->userManager);
	}

	// TODO: control job execution times better (e.g.only at night)
	// The uncommented tests fail as NextCLoud has no working mechanism for it
	//
	// public function testJobIntervalEarly() {
	//     $this->config->expects($this->once())
	//         ->method("getAppValue")
	//         ->with($this->equalTo('nmcprovisioning'), $this->equalTo('deletionjobtime'))
	//         ->willReturn('04:00:00');

	//     $refTime = new \DateTime("01:23:01");
	//     $interval = $this->job->setLastRun($refTime->getTimestamp());
	//     $this->assertEquals(59 + 36*60 + 2*3600 , $this->job->getInterval());
	// }

	// public function testJobIntervalLate() {
	//     $this->config->expects($this->once())
	//         ->method("getAppValue")
	//         ->with($this->equalTo('nmcprovisioning'), $this->equalTo('deletionjobtime'))
	//         ->willReturn('05:00:00');

	//     $refTime = new \DateTime("11:23:33");Mura
	//     $interval = $this->job->computeDestinationInterval($refTime);
	//     $this->assertEquals(27 + 36*60 + 17*3600 , $interval);
		
	// }

	// public function testJobIntervalZero() {
	//     $this->config->expects($this->once())
	//         ->method("getAppValue")
	//         ->with($this->equalTo('nmcprovisioning'), $this->equalTo('deletionjobtime'))
	//         ->willReturn('17:00:00');

	//     $refTime = new \DateTime("17:00:00");
	//     $interval = $this->job->computeDestinationInterval($refTime);
	//     $this->assertEquals(24*3600 , $interval);
	// }


	public function testJobRunNone() {
		//$this->config->expects($this->once())
		//    ->method("getAppValue")
		//    ->with($this->equalTo('nmcprovisioning'), $this->equalTo('deletionjobtime'))
		//    ->willReturn('04:00:00');

		$this->userQueries->expects($this->once())
			->method("findDeletions")
			->willReturn([]);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->expects($this->never())->method("delete");
		$this->userManager->expects($this->never())->method("get");

		$this->job->run(null);
	}

	public function testJobRunMultiple() {
		//$this->config->expects($this->once())
		//    ->method("getAppValue")
		//    ->with($this->equalTo('nmcprovisioning'), $this->equalTo('deletionjobtime'))
		//    ->willReturn('04:00:00');

		$this->userQueries->expects($this->once())
			->method("findDeletions")
			->willReturn(['120042010000000004200001',
				'120042010000000004200002',
				'120042010000000004200003']);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->expects($this->exactly(3))->method("delete");
		$this->userManager->expects($this->exactly(3))
						->method("get")
						->withConsecutive([$this->equalTo('120042010000000004200001')],
							[$this->equalTo('120042010000000004200002')],
							[$this->equalTo('120042010000000004200003')])
						->willReturn($user);
		$this->job->run(null);
	}

	public function testJobRunOne() {
		//$this->config->expects($this->once())
		//    ->method("getAppValue")
		//    ->with($this->equalTo('nmcprovisioning'), $this->equalTo('deletionjobtime'))
		//    ->willReturn('04:00:00');

		$this->userQueries->expects($this->once())
			->method("findDeletions")
			->willReturn(['120042010000000004200001']);

		$user = $this->getMockForAbstractClass(IUser::class);
		$user->expects($this->once())->method("delete");
		$this->userManager->expects($this->once(0))
						->method("get")
						->with($this->equalTo('120042010000000004200001'))
						->willReturn($user);

		$this->job->run(null);
	}


}
