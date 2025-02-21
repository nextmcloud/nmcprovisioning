<?php

namespace OCA\NextMagentaCloudProvisioning\Event;

use OCA\NextMagentaCloudProvisioning\Rules\DisplaynameRules;
use OCA\NextMagentaCloudProvisioning\Rules\TariffRules;
use OCA\UserOIDC\Event\AttributeMappedEvent;
use OCA\UserOIDC\Service\ProviderService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

class UserAttributeListener implements IEventListener {

	/** @var LoggerInterface */
	private $logger;

	/** @var TariffRules */
	private $tariffRules;

	/** @var DisplaynameRules */
	private $displaynameRules;


	public function __construct(LoggerInterface $logger,
		TariffRules $tariffRules,
		DisplaynameRules $displaynameRules) {
		$this->logger = $logger;
		$this->tariffRules = $tariffRules;
		$this->displaynameRules = $displaynameRules;
	}

	public function handle(Event $event): void {
		$this->logger->debug("AttributeMappedEvent");
		if ($event instanceof AttributeMappedEvent) {
			$this->logger->debug($event->getAttribute() . " (Oidc mapping) received.");
			if ($event->getAttribute() == ProviderService::SETTING_MAPPING_QUOTA) {
				$this->onQuotaMapping($event);
			} elseif ($event->getAttribute() == ProviderService::SETTING_MAPPING_DISPLAYNAME) {
				$this->onDisplayNameMapping($event);
			}
		}
	}

	/**
	 * NextMagentaCloud wants to have shorter displaynames, but SAM3 only delivers displaynames
	 * with mail domain, so we remove the domain to get consistent with SLUP `alia` attribute
	 */
	protected function onDisplayNameMapping(AttributeMappedEvent $attrEvent) {
		$claims = $attrEvent->getClaims();
		$this->logger->debug($attrEvent->getAttribute() . " processing: " . json_encode(get_object_vars($claims)));

		$displayname = $this->displaynameRules->deriveDisplayname($claims);
		$attrEvent->setValue($displayname);
	}

	protected function onQuotaMapping(AttributeMappedEvent $attrEvent) {
		$claims = $attrEvent->getClaims();
		$this->logger->debug($attrEvent->getAttribute() . " processing: " . json_encode(get_object_vars($claims)));

		$quota = $this->tariffRules->deriveQuota($claims);
		$attrEvent->setValue($quota);
	}
}
