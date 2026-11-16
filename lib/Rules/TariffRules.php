<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCA\NextMagentaCloudProvisioning\Logger\ProvisioningLogger;

class TariffRules {

	private GroupTariffMapping $groupTariffMapping;
	private ProvisioningLogger $provisioningLogger;

	public function __construct(GroupTariffMapping $groupTariffMapping, ProvisioningLogger $provisioningLogger) {
		$this->groupTariffMapping = $groupTariffMapping;
		$this->provisioningLogger = $provisioningLogger;
	}

	private function quotaNoFlags(object $claims, array $tariffs): string {
		$anyFlagSet = array_reduce($tariffs, function (bool $carry, array $tariff) use ($claims): bool {
			if ($carry || !array_key_exists('flag', $tariff)) {
				return $carry;
			}
			return $this->isQuotaFlagSet($claims, $tariff);
		}, false);

		return $anyFlagSet ? '0 B' : $tariffs['NOFLAGS']['space_limit'];
	}

	private function isQuotaFlagSet(object $claims, array $tariff): bool {
		$flagName = $tariff['flag'];
		return property_exists($claims, $flagName) && (string)$claims->{$flagName} === '1';
	}

	private function maxQuota(string $left, string $right): string {
		return \OCP\Util::computerFileSize($left) > \OCP\Util::computerFileSize($right) ? $left : $right;
	}

	public function deriveQuota(object $claims): string {
		$tariffs = $this->groupTariffMapping->getGroupMapping();

		$quotaCandidates = array_map(function (array $tariff) use ($claims): string {
			if (!array_key_exists('flag', $tariff)) {
				return '0 B';
			}
			return $this->isQuotaFlagSet($claims, $tariff) ? $tariff['space_limit'] : '0 B';
		}, $tariffs);

		$maxQuota = array_reduce($quotaCandidates, [$this, 'maxQuota'], $this->quotaNoFlags($claims, $tariffs));

		$activeFlagCount = 0;
		foreach ($tariffs as $tariff) {
			if (array_key_exists('flag', $tariff) && $this->isQuotaFlagSet($claims, $tariff)) {
				$activeFlagCount++;
			}
		}

		$this->provisioningLogger->debug('Derived quota from tariff claims', [
			'activeFlagCount' => $activeFlagCount,
			'quota' => $maxQuota,
		]);

		return $maxQuota;
	}
}
