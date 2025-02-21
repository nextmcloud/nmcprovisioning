<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCA\NextMagentaCloudProvisioning\Event\UserAttributeListener;
use OCA\NextMagentaCloudProvisioning\Rules\DisplaynameRules;
use OCA\NextMagentaCloudProvisioning\Rules\TariffRules;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCA\UserOIDC\Service\ProviderService;
use OCP\AppFramework\App;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttributeMappedQuotaTest extends TestCase {
	public const DEMO_CLAIM = <<<JSON
    {   "sub":"120049010000000009260079","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1",
        "iss":"https://telekom.example.com/","urn:telekom.com:f460":"0","urn:telekom.com:anid":"120049011000000009260047",
        "urn:telekom.com:f048":"1","urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
        "urn:telekom.com:d556":"0","auth_time":1637337383,"exp":1637340983,"iat":1637337383,
        "urn:telekom.com:name":"Melona", "urn:telekom.com:zusa":"Savanna",
        "urn:telekom.com:displayname":"Melona, Savanna", 
        "urn:telekom.com:email":"johnny.gyr@ver.sul", 
        "urn:telekom.com:mainEmail":"johnny.gyros@ver.sul.t-online.de",
        "urn:telekom.com:extMail":"johnny.gyros@bigmail.de",
        "urn:telekom.com:extmail":"johnny.gyros@littlemail.de",
        "urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
        "urn:telekom.com:session_token":"3eabd531-4951-11ec-b3eb-fdc51fc918dc","nonce":"YGJXOZ5T01LPV04OTK1UHWOSVKMPC2VG",
        "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0", "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],
        "urn:telekom.com:f469":"0" }
    JSON;


	public function setUp(): void {
		parent::setUp();
		$app = new App(Application::APP_ID);
		$this->listener = new UserAttributeListener($app->getContainer()->get(LoggerInterface::class),
			$app->getContainer()->get(TariffRules::class),
			$app->getContainer()->get(DisplaynameRules::class));
	}

	public const CLAIM_NOFLAGS = <<<JSON
    {   "sub":"120049010000000009260079","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1","urn:telekom.com:email":"johnny.gyros@ver.sul.t-online.de",
        "iss":"https:\/\/accounts.login00.idm.ver.sul.t-online.de","urn:telekom.com:f460":"0","urn:telekom.com:anid":"120049011000000009260047",
        "urn:telekom.com:f048":"0","urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
        "urn:telekom.com:d556":"0","auth_time":1637337383,"exp":1637340983,"iat":1637337383,"urn:telekom.com:mainEmail":"johnny.gyros@ver.sul.t-online.de",
        "urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
        "urn:telekom.com:session_token":"3eabd531-4951-11ec-b3eb-fdc51fc918dc","nonce":"YGJXOZ5T01LPV04OTK1UHWOSVKMPC2VG",
        "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0", "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],
        "urn:telekom.com:f469":"0" }
    JSON;

	public function testNoRateFlagQuota() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, json_decode(self::CLAIM_NOFLAGS), null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('25 GB', $event->getValue());
	}

	public function testFree3GBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f048'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('3 GB', $event->getValue());
	}

	public function testFree10GBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f460'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('10 GB', $event->getValue());
	}

	public function testS15GBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f049'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('15 GB', $event->getValue());
	}

	public function testS25GBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f467'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('25 GB', $event->getValue());
	}

	public function testS64GBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f008'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('64 GB', $event->getValue());
	}

	public function testM100GBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f468'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('100 GB', $event->getValue());
	}

	public function testL500GBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f469'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('500 GB', $event->getValue());
	}

	public function testXL1TBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f471'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('1 TB', $event->getValue());
	}

	public function testXXL5TBQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f051'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('5 TB', $event->getValue());
	}

	public function testXL1TBPlusOlderBookingQuota() {
		$claims = json_decode(self::CLAIM_NOFLAGS);
		$claims->{'urn:telekom.com:f467'} = '1';
		$claims->{'urn:telekom.com:f471'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals('1 TB', $event->getValue());
	}


}
