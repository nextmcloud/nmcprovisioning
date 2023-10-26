<?php

namespace OCA\NextMagentaCloudProvisioning\Event;

use OCA\NextMagentaCloudProvisioning\Rules\UserAccountRules;

use OCA\UserOIDC\Event\UserAccountChangeEvent;
use OCP\EventDispatcher\Event;

use OCP\EventDispatcher\IEventListener;

use OCP\ILogger;

class UserAccountChangeListener implements IEventListener {

	/** @var ILogger */
	private $logger;

	/** @var UserAccountRules */
	private $accountRules;


	public function __construct(ILogger $logger,
		UserAccountRules $accountRules) {
		$this->logger = $logger;
		$this->accountRules = $accountRules;
	}

	public function handle(Event $event): void {
		if ($event instanceof UserAccountChangeEvent) {
			$this->logger->debug("{$event->getUID()}: UserAccountChangeEvent received.");
			$this->onChangeAccount($event);
		}
	}

	/**
	 * NextMagentaCloud wants to have shorter displaynames, but SAM3 only delivers displaynames
	 * with mail domain, so we remove the domain to get consistent with SLUP `alia` attribute
	 */
	protected function onChangeAccount(UserAccountChangeEvent $event) {
		$claims = $event->getClaims();
		$this->logger->debug("Account change event: " . json_encode(get_object_vars($claims)));

		$evalResult = $this->accountRules->deriveAccountState($event->getUid(), $event->getDisplayName(),
			$event->getMainEmail(), $event->getQuota(), $claims);
		if (!array_key_exists('redirect', $evalResult)) {
			$event->setResult($evalResult['allowed'], $evalResult['reason']);
		} else {
			$event->setResult($evalResult['allowed'], $evalResult['reason'], $evalResult['redirect']);
		}
	}

}
