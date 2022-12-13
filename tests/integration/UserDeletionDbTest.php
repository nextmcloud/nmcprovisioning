<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCA\NextMagentaCloudProvisioning\TestHelper\StackedCleanupTestCase;

use OCP\ILogger;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IServerContainer;
use OCP\Accounts\IAccountManager;


use OCA\NextMagentaCloudProvisioning\AppInfo\Application;

use OCA\UserOIDC\Db\UserMapper;
use OCA\UserOIDC\Db\ProviderMapper;

use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCA\NextMagentaCloudProvisioning\User\NmcUserService;

class UserDeletionDbTest extends StackedCleanupTestCase {
	public function setUp(): void {
		parent::setUp();
		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		//$this->config = $this->getMockBuilder(IConfig::class)
		//                    ->enableProxyingToOriginalMethods()
		//                    ->setProxyTarget($this->app->getContainer()->get(IConfig::class))
		//                    ->getMock();
		$this->config = $this->app->getContainer()->get(IConfig::class);
		// TODO: env variables or take from central config
		// e.g. bootstrap.php
		// require_once __DIR__ . '/../../server/lib/base.php';
		//$this->config->setSystemValue('datadirectory', '/var/www/html/data');
		//$this->config->setSystemValue('dbtype', 'sqlite3');

		$this->logger = $this->app->getContainer()->get(ILogger::class);
		$this->userServiceMock = $this->getMockBuilder(NmcUserService::class)
									->setConstructorArgs([ $this->app->getContainer()->get(IUserManager::class),
										$this->app->getContainer()->get(IAccountManager::class),
										$this->app->getContainer()->get(IServerContainer::class),
										$this->config,
										$this->app->getContainer()->get(UserMapper::class),
										$this->app->getContainer()->get(ProviderMapper::class)])
									->onlyMethods(['create', 'update'])
									->getMock();

		$this->userQueries = $this->app->getContainer()->get(UserQueries::class);
		//$this->userServiceMock->unmarkDeletion("120049010000000009260079");
		//$this->userServiceMock->unmarkDeletion("120042010000000004200002");
		//$this->userServiceMock->unmarkDeletion("120042010000000004200003");
	}

	private function filterTestData(array $haystack) : array {
		return \array_filter($haystack, function ($v) {
			return \in_array($v, ['120042010000000004200001',
				'120042010000000004200002',
				'120042010000000004200003']);
		});
	}

	/**
	 * This test should fail if integration database is not empty
	 */
	public function testDeletionsResultEmptyEmpty() {
		$refDateTime = new \DateTime();
		$refDateTime->add(new \DateInterval("PT1M"));

		$uidCandidates = $this->userQueries->findDeletions($refDateTime);
		$uids = $this->filterTestData($uidCandidates);
		$this->assertEmpty($uids);
	}

	public function testDeletionsResultNonEmptyAll() {
		$delDateTime = new \DateTime();
		$refDateTime = clone $delDateTime;
		$refDateTime->add(new \DateInterval("PT7M"));

		$this->config->setAppValue('nmcprovisioning', 'userretention', "PT1M");
		$this->addCleanup(function () {
			$this->config->deleteAppValue('nmcprovisioning', 'userretention');
		});

		//$deletionDate = clone $refDateTime;
		//$deletionDate->add( new \DateInterval("PT1H") );

		$this->userServiceMock->markDeletion("120042010000000004200001", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200001");
		});
		$this->userServiceMock->markDeletion("120042010000000004200002", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200002");
		});
		$this->userServiceMock->markDeletion("120042010000000004200003", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200003");
		});

		$uidCandidates = $this->userQueries->findDeletions($refDateTime);
		$uids = $this->filterTestData($uidCandidates);
		$this->assertNotEmpty($uids);
		$this->assertEquals(3, count($uids));
	}

	public function testDeletionsResultEmptyAll() {
		$delDateTime = new \DateTime();
		$refDateTime = clone $delDateTime;
		$refDateTime->add(new \DateInterval("PT7M"));

	
		$this->config->setAppValue('nmcprovisioning', 'userretention', "PT11M");
		$this->addCleanup(function () {
			$this->config->deleteAppValue('nmcprovisioning', 'userretention');
		});

		$this->userServiceMock->markDeletion("120042010000000004200001", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200001");
		});
		$this->userServiceMock->markDeletion("120042010000000004200002", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200002");
		});
		$this->userServiceMock->markDeletion("120042010000000004200003", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200003");
		});

		$uidCandidates = $this->userQueries->findDeletions($refDateTime);
		$uids = $this->filterTestData($uidCandidates);
		$this->assertEmpty($uids);
	}


	public function testDeletionsResultOne() {
		$delDateTime = new \DateTime();
		$refDateTime = clone $delDateTime;
		$refDateTime->add(new \DateInterval("PT7M"));

		$this->config->setAppValue('nmcprovisioning', 'userretention', "PT1M");
		$this->addCleanup(function () {
			$this->config->deleteAppValue('nmcprovisioning', 'userretention');
		});

		//$deletionDate = clone $refDateTime;
		//$deletionDate->add( new \DateInterval("PT1H") );

		$this->userServiceMock->markDeletion("120042010000000004200001", $refDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200001");
		});
		$this->userServiceMock->markDeletion("120042010000000004200002", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200002");
		});
		$this->userServiceMock->markDeletion("120042010000000004200003", $refDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200003");
		});

		$uidCandidates = $this->userQueries->findDeletions($refDateTime);
		$uids = $this->filterTestData($uidCandidates);
		$this->assertNotEmpty($uids);
        $this->assertContains("120042010000000004200002", $uids);
		$this->assertEquals(1, count($uids));
    }

	public function testDeletionsResultOneAll() {
		$delDateTime = new \DateTime();
		$refDateTime = clone $delDateTime;
		$refDateTime->add(new \DateInterval("PT7M"));

		$this->config->setAppValue('nmcprovisioning', 'userretention', "PT1M");
		$this->addCleanup(function () {
			$this->config->deleteAppValue('nmcprovisioning', 'userretention');
		});

		//$deletionDate = clone $refDateTime;
		//$deletionDate->add( new \DateInterval("PT1H") );

		$this->userServiceMock->markDeletion("120042010000000004200002", $delDateTime);
		$this->addCleanup(function () {
			$this->userServiceMock->unmarkDeletion("120042010000000004200002");
		});

		$uidCandidates = $this->userQueries->findDeletions($refDateTime);
		$uids = $this->filterTestData($uidCandidates);
		$this->assertNotEmpty($uids);
        $this->assertContains("120042010000000004200002", $uids);
		$this->assertEquals(1, count($uids));
    }

}
