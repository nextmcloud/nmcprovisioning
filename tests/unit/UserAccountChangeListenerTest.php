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
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserAccountChangeListenerTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->config = $this->getMockForAbstractClass(IConfig::class);
		$this->logger = $this->app->getContainer()->get(LoggerInterface::class);
		$this->userManager = $this->getMockForAbstractClass(IUserManager::class);
		$this->oidcProviderMapper = $this->app->getContainer()->get(ProviderMapper::class);
		$this->userService = $this->getMockBuilder(NmcUserService::class)
									->setConstructorArgs([ $this->app->getContainer()->get(IUserManager::class),
										$this->app->getContainer()->get(IAccountManager::class),
										$this->logger,
										$this->config,
										$this->app->getContainer()->get(UserMapper::class),
										$this->app->getContainer()->get(ProviderMapper::class),
										$this->app->getContainer()->get(IGroupManager::class)])
									->onlyMethods(['create', 'update', 'userExists'])
									->getMock();
		$this->accountRules = new UserAccountRules($this->config,
			$this->logger,
			$this->userService,
			$this->userManager,
			$this->oidcProviderMapper);
		$this->listener = new UserAccountChangeListener($this->logger, $this->accountRules);

		$this->config->expects($this->any())
		->method("getAppValue")
		->with($this->equalTo('nmcprovisioning'),
			$this->logicalOr($this->equalTo('userretention'),
				$this->equalTo('useraccessurl'),
				$this->equalTo('userwithdrawurl'),
				$this->equalTo('userpreserveurl'),
				$this->equalTo('userotturl')))
		->willReturn($this->returnCallback(function ($app, $key) {
			$values = [
				'userwithdrawurl' => "https://cloud.telekom-dienste.de",
				'userpreserveurl' => "https://telekom.example.com/",
				'useraccessurl' => "https://cloud.telekom-dienste.de/tarife",
				'userotturl' => "https://cloud.telekom-dienste.de/ott",
				'userretention' => "P60D",

			];

			return $values[$key];
		}));
	}

	public function testWithdrawNonExisting() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"0","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);

		$this->userService->expects($this->once())
						->method('userExists')
						->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
						->willReturn(false);
	
		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3GB", $oidcClaims);
		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('No tariff no new account', $event->getResult()->getReason());
		$this->assertNotNull($event->getResult()->getRedirectUrl());
		$this->assertEquals("https://cloud.telekom-dienste.de", $event->getResult()->getRedirectUrl());
	}

	public function testWithdrawNoUsta() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"0","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);

		$this->userService->expects($this->once())
						->method('userExists')
						->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
						->willReturn(true);
		$this->config->expects($this->once())
					->method("setUserValue")
					->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"));
		$this->userService->expects($this->once())
						->method('update')
						->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"),
							$this->equalTo("jonny.gyros"), $this->equalTo("jonny.gyros@ver.sul.t-online.de"),
							$this->isNull(), $this->equalTo("3 GB"), $this->isFalse(), $this->isFalse())
						->willReturn(false);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('Withdrawn', $event->getResult()->getReason());
		$this->assertNotNull($event->getResult()->getRedirectUrl());
		$this->assertEquals("https://cloud.telekom-dienste.de", $event->getResult()->getRedirectUrl());
	}

	public function testPreserveOtherUsta() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0","urn:telekom.com:usta":"0",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"0","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"1",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);

		$this->userService->expects($this->once())
							->method('userExists')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
							->willReturn(true);
		$this->config->expects($this->once())
							->method("setUserValue")
							->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"));
		$this->userService->expects($this->once())
							->method('update')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"),
								$this->equalTo("jonny.gyros"), $this->equalTo("jonny.gyros@ver.sul.t-online.de"),
								$this->isNull(), $this->equalTo("3 GB"), $this->isFalse(), $this->isFalse())
							->willReturn(false);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('Withdrawn', $event->getResult()->getReason());
		$this->assertNotNull($event->getResult()->getRedirectUrl());
		$this->assertEquals('https://telekom.example.com/', $event->getResult()->getRedirectUrl());
	}


	public function testWithdrawPreserve() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"0","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"1",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:usta":"3",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);
		
		$this->userService->expects($this->once())
							->method('userExists')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
							->willReturn(true);
		$this->config->expects($this->once())
							->method("setUserValue")
							->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"));
		$this->userService->expects($this->once())
							->method('update')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"),
								$this->equalTo("jonny.gyros"), $this->equalTo("jonny.gyros@ver.sul.t-online.de"),
								$this->isNull(), $this->equalTo("3 GB"), $this->isFalse(), $this->isFalse())
							->willReturn(false);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('Withdrawn', $event->getResult()->getReason());
		$this->assertNotNull($event->getResult()->getRedirectUrl());
		$this->assertEquals('https://telekom.example.com/', $event->getResult()->getRedirectUrl());
	}

	public function testWithdrawOTT() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"0","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);
		
		$this->userService->expects($this->once())
							->method('userExists')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
							->willReturn(true);
		$this->config->expects($this->once())
							->method("setUserValue")
							->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"));
		$this->userService->expects($this->once())
							->method('update')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"),
								$this->equalTo("jonny.gyros"), $this->equalTo("jonny.gyros@ver.sul.t-online.de"),
								$this->isNull(), $this->equalTo("3 GB"), $this->isFalse(), $this->isFalse())
							->willReturn(false);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('Withdrawn', $event->getResult()->getReason());
		$this->assertNotNull($event->getResult()->getRedirectUrl());
		$this->assertEquals("https://cloud.telekom-dienste.de/ott", $event->getResult()->getRedirectUrl());
	}

	public function testWithdrawAccess() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0","urn:telekom.com:usta":"3",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"0","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);
		
		$this->userService->expects($this->once())
							->method('userExists')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
							->willReturn(true);
		$this->config->expects($this->once())
							->method("setUserValue")
							->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"));
		$this->userService->expects($this->once())
							->method('update')
							->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"),
								$this->equalTo("jonny.gyros"), $this->equalTo("jonny.gyros@ver.sul.t-online.de"),
								$this->isNull(), $this->equalTo("3 GB"), $this->isFalse(), $this->isFalse())
							->willReturn(false);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('Withdrawn', $event->getResult()->getReason());
		$this->assertNotNull($event->getResult()->getRedirectUrl());
		$this->assertEquals("https://cloud.telekom-dienste.de/tarife", $event->getResult()->getRedirectUrl());
	}

	public function testUnWithdraw() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"1",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);

		$this->userService->expects($this->once())
			->method('userExists')
			->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
			->willReturn(true);
		$this->config->expects($this->once())
			->method("deleteUserValue")
			->with($this->equalTo('12004901000000000XXXXXXX'), Application::APP_ID, $this->equalTo("deletion"));
		$this->userService->expects($this->once())
			->method('update')
			->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"),
				$this->equalTo("jonny.gyros"), $this->equalTo("jonny.gyros@ver.sul.t-online.de"),
				$this->isNull(), $this->equalTo("3 GB"), $this->isFalse(), $this->isTrue())
			->willReturn(true);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertTrue($event->getResult()->isAccessAllowed());
		$this->assertEquals('Updated', $event->getResult()->getReason());
		$this->assertNull($event->getResult()->getRedirectUrl());
	}

	public function testLock() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"1","urn:telekom.com:usta":"1",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"1",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);

		$this->userService->expects($this->once())
			->method('userExists')
			->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
			->willReturn(true);
		$this->userService->expects($this->once())
			->method('update')
			->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"),
				$this->equalTo("jonny.gyros"), $this->equalTo("jonny.gyros@ver.sul.t-online.de"),
				$this->isNull(), $this->equalTo("3 GB"), $this->isFalse(), $this->isFalse())
			->willReturn(true);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('Locked', $event->getResult()->getReason());
		$this->assertNull($event->getResult()->getRedirectUrl());
	}

	public function testLockNonExisting() {
		$oidcClaims = json_decode(<<<JSON
            {"sub":"12004901000000000XXXXXXX","urn:telekom.com:s556":"1","urn:telekom.com:usta":"1",
            "urn:telekom.com:email":"jonny.gyros@ver.sul.t-online.de","iss":"https://telekom.example.com/",
            "urn:telekom.com:f460":"0","urn:telekom.com:anid":"12004901000000000XXXXXXX","urn:telekom.com:f048":"1",
            "urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"1",
            "urn:telekom.com:d556":"0","auth_time":1637683330,"exp":1637686930,"iat":1637683330,
            "urn:telekom.com:mainEmail":"jonny.gyros@ver.sul.t-online.de","urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
            "urn:telekom.com:displayname":"jonny.gyros@ver.sul.t-online.de",
            "urn:telekom.com:session_token":"b71ce9a1-4c76-11ec-a456-6919c2d53a81","nonce":"H6VXIR86HC6C4Z41F0N10V8OJ49INS0J",
            "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0",
            "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],"urn:telekom.com:f469":"0"}
            JSON);

		$this->userService->expects($this->once())
			->method('userExists')
			->with($this->equalTo('Telekom'), $this->equalTo("12004901000000000XXXXXXX"))
			->willReturn(false);

		$event = new UserAccountChangeEvent("12004901000000000XXXXXXX", "jonny.gyros",
			"jonny.gyros@ver.sul.t-online.de", "3 GB", $oidcClaims);

		$this->listener->handle($event);
		$this->assertFalse($event->getResult()->isAccessAllowed());
		$this->assertEquals('Locked no new account', $event->getResult()->getReason());
		$this->assertNull($event->getResult()->getRedirectUrl());
	}
}
