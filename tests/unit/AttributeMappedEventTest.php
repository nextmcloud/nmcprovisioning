<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCP\ILogger;


use OCP\AppFramework\App;
use OCA\NextMagentaCloudProvisioning\AppInfo\Application;

use OCA\NextMagentaCloudProvisioning\Rules\TariffRules;
use OCA\NextMagentaCloudProvisioning\Event\UserAttributeListener;

use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCA\UserOIDC\Service\ProviderService;

use PHPUnit\Framework\TestCase;

class AttributeMappedEventTest extends TestCase {
	public const DEMO_CLAIM1 = <<<JSON
    {   "sub":"120049010000000009260079","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1","urn:telekom.com:email":"johnny.gyros@ver.sul.t-online.de",
        "iss":"https://telekom.example.com/","urn:telekom.com:f460":"0","urn:telekom.com:anid":"120049011000000009260047",
        "urn:telekom.com:f048":"1","urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
        "urn:telekom.com:d556":"0","auth_time":1637337383,"exp":1637340983,"iat":1637337383,"urn:telekom.com:mainEmail":"johnny.gyros@ver.sul.t-online.de",
        "urn:telekom.com:f051":"0","urn:telekom.com:f471":"0","urn:telekom.com:displayname":"johnny.gyros@ver.sul.t-online.de",
        "urn:telekom.com:session_token":"3eabd531-4951-11ec-b3eb-fdc51fc918dc","nonce":"YGJXOZ5T01LPV04OTK1UHWOSVKMPC2VG",
        "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0", "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],
        "urn:telekom.com:f469":"0" }
    JSON;


	public function setUp(): void {
		parent::setUp();
		$app = new App(Application::APP_ID);
		$this->listener = new UserAttributeListener($app->getContainer()->get(ILogger::class),
													$app->getContainer()->get(TariffRules::class));
	}

	public function testDisplayNameRemoveDomain() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, json_decode(self::DEMO_CLAIM1), "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("johnny.gyros", $event->getValue());
	}

	public const DEMO_CLAIM2 = <<<JSON
    {   "sub":"120049010000000009260079","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1","urn:telekom.com:email":"johnny.gyros@ver.sul.t-online.de",
        "iss":"https://telekom.example.com/","urn:telekom.com:f460":"0","urn:telekom.com:anid":"120049011000000009260047",
        "urn:telekom.com:f048":"1","urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
        "urn:telekom.com:d556":"0","auth_time":1637337383,"exp":1637340983,"iat":1637337383,"urn:telekom.com:mainEmail":"johnny.gyros@ver.sul.t-online.de",
        "urn:telekom.com:f051":"0","urn:telekom.com:f471":"0","urn:telekom.com:displayname":"johnny.gyros",
        "urn:telekom.com:session_token":"3eabd531-4951-11ec-b3eb-fdc51fc918dc","nonce":"YGJXOZ5T01LPV04OTK1UHWOSVKMPC2VG",
        "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0", "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],
        "urn:telekom.com:f469":"0" }
    JSON;

	public function testDisplayNameAlreadyNoDomain() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, json_decode(self::DEMO_CLAIM2), "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("johnny.gyros", $event->getValue());
	}

	public function testDisplayNameIgnoreNullDefault() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, json_decode(self::DEMO_CLAIM2), null);
		$this->listener->handle($event);
		$this->assertEquals("johnny.gyros", $event->getValue());
	}


	public const DEMO_CLAIM3 = <<<JSON
    {   "sub":"120049010000000009260079","urn:telekom.com:s556":"0","urn:telekom.com:usta":"1","urn:telekom.com:email":"johnny.gyros@ver.sul.t-online.de",
        "iss":"https:\/\/accounts.login00.idm.ver.sul.t-online.de","urn:telekom.com:f460":"0","urn:telekom.com:anid":"120049011000000009260047",
        "urn:telekom.com:f048":"1","urn:telekom.com:f556":"1","acr":"urn:telekom:names:idm:THO:1.0:ac:classes:passid:00","urn:telekom.com:f734":"0",
        "urn:telekom.com:d556":"0","auth_time":1637337383,"exp":1637340983,"iat":1637337383,"urn:telekom.com:mainEmail":"johnny.gyros@ver.sul.t-online.de",
        "urn:telekom.com:f051":"0","urn:telekom.com:f471":"0",
        "urn:telekom.com:session_token":"3eabd531-4951-11ec-b3eb-fdc51fc918dc","nonce":"YGJXOZ5T01LPV04OTK1UHWOSVKMPC2VG",
        "urn:telekom.com:f468":"0","urn:telekom.com:f049":"0","urn:telekom.com:f467":"0", "aud":["10TVL0SAM30000004901NEXTMAGENTACLOUDTEST"],
        "urn:telekom.com:f469":"0" }
    JSON;

	public function testNoDisplayName() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, json_decode(self::DEMO_CLAIM3), "santa.foo@magenta.de");
		$this->listener->handle($event);
    	// current workaround is to ignore displayname completely until name/additionalName is available
        //	$this->assertEquals("santa.foo", $event->getValue());
		$this->assertEquals("johnny.gyros", $event->getValue());
	}

	public function testNoDisplayNameNullDefault() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, json_decode(self::DEMO_CLAIM3), null);
		$this->listener->handle($event);
    	// current workaround is to ignore displayname completely until name/additionalName is available
		// $this->assertNull($event->getValue());
		$this->assertEquals("johnny.gyros", $event->getValue());
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
		$this->assertEquals(TariffRules::NMC_RATE_S25, $event->getValue());
	}

    public function testFree3GBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f048'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_FREE3, $event->getValue());
	}

    public function testFree10GBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f460'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_FREE10, $event->getValue());
	}

    public function testS15GBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f049'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_S15, $event->getValue());
	}

    public function testS25GBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f467'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_S25, $event->getValue());
	}

    public function testS64GBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f008'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_S25, $event->getValue());
	}

    public function testM100GBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f468'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_M100, $event->getValue());
	}

    public function testL500GBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f469'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_L500, $event->getValue());
	}

    public function testXL1TBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f471'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_XL1, $event->getValue());
	}

    public function testXXL5TBQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f051'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_XXL5, $event->getValue());
	}

    public function testXL1TBPlusOlderBookingQuota() {
        $claims = json_decode(self::CLAIM_NOFLAGS);
        $claims->{'urn:telekom.com:f467'} = '1';
        $claims->{'urn:telekom.com:f471'} = '1';
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_QUOTA, $claims, null);
		$this->listener->handle($event);
		$this->assertNotNull($event->getValue());
		$this->assertEquals(TariffRules::NMC_RATE_XL1, $event->getValue());
	}


}
