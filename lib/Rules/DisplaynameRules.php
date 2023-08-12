<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCA\NextMagentaCloudProvisioning\Logger\ProvisioningLogger;

/**
 * The generic tariff rules evaluator
 */
class DisplaynameRules {

	private GroupTariffMapping $groupTariffMapping;

	/** @var ProvisioningLogger */
	private ProvisioningLogger $provisioningLogger;


	public function __construct(GroupTariffMapping $groupTariffMapping, ProvisioningLogger $provisioningLogger) {
		$this->groupTariffMapping = $groupTariffMapping;
		$this->provisioningLogger = $provisioningLogger;
	}

	/**
	 * Central rule to derive displayname
	 * consistently from SAM/SLUP fields
	 */
	public function deriveDisplayname(object $claims) {
		$displayname = "";
		$this->provisioningLogger->debug("test");
		foreach ($this->groupTariffMapping->getDisplaynameSearch() as $search) {
			// check if all attributes match
			$searchAttrs = array_map(function ($field) { return 'urn:telekom.com:' . $field; }, $search);
			$allFieldsMatch = array_reduce($searchAttrs, function ($carry, $field) use ($claims) {
				return !property_exists($claims, $field) ? false : $carry;
			}, true);
			// the displayname is only complete if all match
			if ($allFieldsMatch) {
				return implode(' ', array_map(function ($id) use ($claims) {
					return $claims->{$id};
				}, $searchAttrs));
			}
		}

		$this->provisioningLogger->warning('Could not derive displayname from claims', ['claims' => $claims]);
		return "-anon-";
	}

}
