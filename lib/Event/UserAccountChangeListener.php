<?php

namespace OCA\NextMagentaCloudProvisioning\Event;

use OCA\NextMagentaCloudProvisioning\Rules\UserAccountRules;
use OCA\UserOIDC\Event\UserAccountChangeEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

class UserAccountChangeListener implements IEventListener {

	private LoggerInterface $logger;
	private UserAccountRules $accountRules;

	public function __construct(LoggerInterface $logger, UserAccountRules $accountRules) {
		$this->logger = $logger;
		$this->accountRules = $accountRules;
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserAccountChangeEvent) {
			return;
		}

		$this->logger->debug("{$event->getUID()}: UserAccountChangeEvent received.");
		$this->onChangeAccount($event);
	}

	protected function onChangeAccount(UserAccountChangeEvent $event): void {
		$claims = $event->getClaims();

		// Log claim names only, never the complete token contents.
		$this->logger->debug("{$event->getUID()}: Account change claims received.", [
			'claimKeys' => array_keys(get_object_vars($claims)),
		]);

		$result = $this->accountRules->deriveAccountState(
			$event->getUID(),
			$event->getDisplayName(),
			$event->getMainEmail(),
			$event->getQuota(),
			$claims
		);

		if (array_key_exists('redirect', $result)) {
			$event->setResult($result['allowed'], $result['reason'], $result['redirect']);
			return;
		}

		$event->setResult($result['allowed'], $result['reason']);
	}
}
