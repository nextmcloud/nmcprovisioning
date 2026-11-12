<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCA\NextMagentaCloudProvisioning\Logger\ProvisioningLogger;

class DisplaynameRules {

	private GroupTariffMapping $groupTariffMapping;
	private ProvisioningLogger $provisioningLogger;

	public function __construct(GroupTariffMapping $groupTariffMapping, ProvisioningLogger $provisioningLogger) {
		$this->groupTariffMapping = $groupTariffMapping;
		$this->provisioningLogger = $provisioningLogger;
	}

	public function deriveDisplayname(object $claims): string {
		foreach ($this->groupTariffMapping->getDisplaynameSearch() as $search) {
			if (empty($search)) {
				continue;
			}

			$fields = array_map(static fn(string $field): string => 'urn:telekom.com:' . $field, $search);

			$allFieldsMatch = array_reduce($fields, static function (bool $carry, string $field) use ($claims): bool {
				return $carry && property_exists($claims, $field);
			}, true);

			if (!$allFieldsMatch) {
				continue;
			}

			$displayName = implode(' ', array_map(
				static fn(string $field): string => (string)$claims->{$field},
				$fields
			));

			$this->provisioningLogger->debug('Derived displayname from configured claims', [
				'fields' => $fields,
			]);

			return $displayName;
		}

		$this->provisioningLogger->warning('Could not derive displayname from claims', [
			'claimKeys' => array_keys(get_object_vars($claims)),
		]);

		return '-anon-';
	}
}
