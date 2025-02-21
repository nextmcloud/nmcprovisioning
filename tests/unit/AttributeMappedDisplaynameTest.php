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

class AttributeMappedDisplaynameTest extends TestCase {
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

		$this->displayNameClaim = json_decode(self::DEMO_CLAIM);
	}

	public function testDisplayNameZusa() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("Savanna Melona", $event->getValue());
	}

	public function testDisplayNameIgnoreNullDefault() {
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, null);
		$this->listener->handle($event);
		$this->assertEquals("Savanna Melona", $event->getValue());
	}

	public function testDisplayNameLowerCaseExtMail() {
		unset($this->displayNameClaim->{'urn:telekom.com:zusa'});
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("johnny.gyros@littlemail.de", $event->getValue());
	}

	public function testDisplayNameCamelCaseExtMail() {
		unset($this->displayNameClaim->{'urn:telekom.com:zusa'});
		unset($this->displayNameClaim->{'urn:telekom.com:extmail'});
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("johnny.gyros@bigmail.de", $event->getValue());
	}

	public function testDisplayNameMainMail() {
		unset($this->displayNameClaim->{'urn:telekom.com:zusa'});
		unset($this->displayNameClaim->{'urn:telekom.com:extmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:extMail'});
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("johnny.gyros@ver.sul.t-online.de", $event->getValue());
	}

	public function testDisplayNameDisplayname() {
		unset($this->displayNameClaim->{'urn:telekom.com:zusa'});
		unset($this->displayNameClaim->{'urn:telekom.com:extmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:extMail'});
		unset($this->displayNameClaim->{'urn:telekom.com:mainEmail'});
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("Melona, Savanna", $event->getValue());
	}

	public function testDisplayNameName() {
		unset($this->displayNameClaim->{'urn:telekom.com:zusa'});
		unset($this->displayNameClaim->{'urn:telekom.com:extmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:extMail'});
		unset($this->displayNameClaim->{'urn:telekom.com:mainEmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:displayname'});
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, "santa.foo@magenta.de");
		$this->listener->handle($event);
		$this->assertEquals("Melona", $event->getValue());
	}

	public function testNoDisplayNameDefault() {
		unset($this->displayNameClaim->{'urn:telekom.com:zusa'});
		unset($this->displayNameClaim->{'urn:telekom.com:extmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:extMail'});
		unset($this->displayNameClaim->{'urn:telekom.com:mainEmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:displayname'});
		unset($this->displayNameClaim->{'urn:telekom.com:name'});
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, "santa.foo@magenta.de");
		$this->listener->handle($event);
		// current workaround is to ignore displayname completely until name/additionalName is available
		//	$this->assertEquals("santa.foo", $event->getValue());
		$this->assertEquals("-anon-", $event->getValue());
	}

	public function testNoDisplayNameNullDefault() {
		unset($this->displayNameClaim->{'urn:telekom.com:zusa'});
		unset($this->displayNameClaim->{'urn:telekom.com:extmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:extMail'});
		unset($this->displayNameClaim->{'urn:telekom.com:mainEmail'});
		unset($this->displayNameClaim->{'urn:telekom.com:displayname'});
		unset($this->displayNameClaim->{'urn:telekom.com:name'});
		$event = new AttributeMappedEvent(ProviderService::SETTING_MAPPING_DISPLAYNAME, $this->displayNameClaim, null);
		$this->listener->handle($event);
		// current workaround is to ignore displayname completely until name/additionalName is available
		// $this->assertNull($event->getValue());
		$this->assertEquals("-anon-", $event->getValue());
	}

}
