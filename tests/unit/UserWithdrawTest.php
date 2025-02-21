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
	public function setUp(): void {
		parent::setUp();
		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->config = $this->getMockForAbstractClass(IConfig::class);
		$this->logger = $this->app->getContainer()->get(LoggerInterface::class);
		$this->userManager = $this->getMockForAbstractClass(IUserManager::class);
		$this->oidcProviderMapper = $this->app->getContainer()->get(ProviderMapper::class);
		$this->userServiceMock = $this->getMockBuilder(NmcUserService::class)
									->setConstructorArgs([ $this->app->getContainer()->get(IUserManager::class),
										$this->app->getContainer()->get(IAccountManager::class),
										$this->logger,
										$this->config,
										$this->app->getContainer()->get(UserMapper::class),
										$this->app->getContainer()->get(ProviderMapper::class),
										$this->app->getContainer()->get(IGroupManager::class)])
									->onlyMethods(['create', 'update'])
									->getMock();
		$this->accountService = new UserAccountRules($this->config,
			$this->logger,
			$this->userServiceMock,
			$this->userManager,
			$this->oidcProviderMapper);
	}

	public function testDeletionDateOidcClaims() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);
		
		$deletionTime = $this->accountService->withdrawDate($oidcClaims);
		$this->assertEquals('2021-11-23T16:02:10+0000', $deletionTime-> format(\DateTimeInterface::ISO8601));
	}
	
	public function testDeletionDateSlupClaims() {
		$slupTestClaims = new \stdClass();
		$slupTestClaims->request = "UTS";
		$slupTestClaims->changeTime = "2021-11-18T08:11:09Z";
		$slupTestClaims->token = "847841948";
		$slupTestClaims->oldfields = new \stdClass();
		$slupTestClaims->newfields = new \stdClass();
		$deletionTime = $this->accountService->withdrawDate($slupTestClaims);
		$this->assertEquals('2021-11-18T08:11:09+0000', $deletionTime-> format(\DateTimeInterface::ISO8601));
	}

	public function testDeletionDateDefault() {
		$now = new \DateTime();
		$deletionTime = $this->accountService->withdrawDate(new \stdClass());
		$this->assertGreaterThanOrEqual($now->getTimestamp(), $deletionTime->getTimestamp());
		$this->assertLessThan($now->getTimestamp() + 3, $deletionTime->getTimestamp());
	}

	public function testMarkDeletionDefault() {
		$withdrawDate = new \DateTime("2021-08-03T16:05:06+0000");
		$expectedDeletionDate = new \DateTime();
		$expectedDeletionDate->setTimestamp($withdrawDate->getTimestamp() + 60 * 24 * 60 * 60);


		$this->config->expects($this->once())
					->method("getAppValue")
					->with($this->equalTo('nmcprovisioning'), $this->equalTo('userretention'), $this->equalTo("P60D"))
					->willReturn("P60D");
		$this->config->expects($this->once())
					->method("setUserValue")
					->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"),
						$this->equalTo($expectedDeletionDate->getTimestamp()));
		$this->config->expects($this->once())
					->method("getUserValue")
					->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"),
						$this->equalTo(null))
					->willReturn($expectedDeletionDate->getTimestamp());
		
		$deletionDate = $this->userServiceMock->markDeletion('12004901000000000XXXXXXX', $withdrawDate);
		$this->assertEquals($expectedDeletionDate, $deletionDate);

		$readDeletionDate = $this->userServiceMock->getDeletionDateTime('12004901000000000XXXXXXX');
		$this->assertNotNull($readDeletionDate);
		$this->assertEquals($expectedDeletionDate, $readDeletionDate);
	}

	public function testMarkDeletionConfig() {
		$withdrawDate = new \DateTime("2021-08-03T16:05:06+0000");
		$expectedDeletionDate = new \DateTime();
		$expectedDeletionDate->setTimestamp($withdrawDate->getTimestamp() + 60 * 60);

		$this->config->expects($this->once())
					->method("getAppValue")
					->with($this->equalTo('nmcprovisioning'), $this->equalTo('userretention'), $this->equalTo("P60D"))
					->willReturn("PT1H");
		$this->config->expects($this->once())
					->method("setUserValue")
					->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"),
						$this->equalTo($expectedDeletionDate->getTimestamp()));
		$this->config->expects($this->once())
					->method("getUserValue")
					->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"),
						$this->equalTo(null))
					->willReturn($expectedDeletionDate->getTimestamp());
		
		$deletionDate = $this->userServiceMock->markDeletion('12004901000000000XXXXXXX', $withdrawDate);
		$this->assertEquals($expectedDeletionDate, $deletionDate);
		
		$readDeletionDate = $this->userServiceMock->getDeletionDateTime('12004901000000000XXXXXXX');
		$this->assertNotNull($readDeletionDate);
		$this->assertEquals($expectedDeletionDate, $readDeletionDate);
	}

	public function testUnmarkDeletionDefault() {
		$this->config->expects($this->once())
					->method("deleteUserValue")
					->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"));
		$this->config->expects($this->once())
					->method("getUserValue")
					->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"),
						$this->equalTo(null))
					->willReturn(null);

		$deletionDate = $this->userServiceMock->unmarkDeletion('12004901000000000XXXXXXX');
		$this->assertNull($this->userServiceMock->getDeletionDateTime('12004901000000000XXXXXXX'));
	}
}
