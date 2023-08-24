<?php

namespace OCA\NextMagentaCloudProvisioning\AppInfo;

use OCA\NextMagentaCloudProvisioning\Event\UserAccountChangeListener;
use OCA\NextMagentaCloudProvisioning\Event\UserAttributeListener;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCA\UserOIDC\Event\UserAccountChangeEvent;

// dependencies to app user_oidc: listen to AttributeMappedEvent
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;

use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'nmcprovisioning';


	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Register the composer autoloader for packages shipped by this app, if applicable
		//include_once __DIR__ . '/../../vendor/autoload.php';

		$context->registerEventListener(AttributeMappedEvent::class, UserAttributeListener::class);
		$context->registerEventListener(UserAccountChangeEvent::class, UserAccountChangeListener::class);
	}

	/**
	 * The boot method seems to be called cyclic in developer mode,
	 * so we cannot use it for SLUP registration on boot
	 */
	public function boot(IBootContext $context): void {
	}
}
