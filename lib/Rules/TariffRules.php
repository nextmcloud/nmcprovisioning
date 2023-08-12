<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCA\NextMagentaCloudProvisioning\Logger\ProvisioningLogger;

/**
 * The generic tariff rules evaluator
 */
class TariffRules {

	private GroupTariffMapping $groupTariffMapping;

	/** @var ProvisioningLogger */
	private ProvisioningLogger $provisioningLogger;


	public function __construct(GroupTariffMapping $groupTariffMapping, ProvisioningLogger $provisioningLogger) {
		$this->groupTariffMapping = $groupTariffMapping;
		$this->provisioningLogger = $provisioningLogger;
	}

	/**
	 * Quota computation helper function to check
	 * claims contain none of the configured flags
	 */
	private function quotaNoFlags(object $claims, $tariffs, $noflagTariff) {
		$noFlagSet = array_reduce($tariffs, function ($carry, $tariff) use ($claims, $noflagTariff) {
			if ($tariff == $noflagTariff) {
				// ignore the traiff without flag rule here
				return $carry;
			}
			$flagname = $tariff['flag'];
			if (property_exists($claims, $flagname) &&
				$claims->{$flagname} === "1") {
				return false;
			} else {
				return $carry;
			}
		}, true);

		if ($noFlagSet) {
			return $noflagTariff['space_limit'];
		} else {
			return "0 B";
		}
	}


	/**
	 * Get the quota if a falg is set
	 * The function does assume that the flag field exists
	 */
	private function quotaFlagSet(object $claims, $tariff) {
		$flagname = $tariff['flag'];
		if (property_exists($claims, $flagname) &&
				$claims->{$flagname} === "1") {
			return $tariff['space_limit'];
		} else {
			return "0 B";
		}
	}

	/**
	 * Compute the maximum of two quotas given
	 * Nextcloud human readable storage sizes
	 */
	private function maxQuota(string $left, string $right) {
		$leftBytes = \OC_Helper::computerFileSize($left);
		$rightBytes = \OC_Helper::computerFileSize($right);
		if ($leftBytes > $rightBytes) {
			return $left;
		} else {
			return $right;
		}
	}



	/**
	 * Central rule to derive quota
	 * consistently from SAM/SLUP fields
	 */
	public function deriveQuota(object $claims) {
		$tariffs = $this->groupTariffMapping->getGroupMapping();

		// for each flag that is set: add the quota limit
		$quotaCandidates = array_map(function ($tariff) use ($claims, $tariffs) {
			if (array_key_exists('flag', $tariff)) {
				return $this->quotaFlagSet($claims, $tariff);
			} else {
				return $this->quotaNoFlags($claims, $tariffs, $tariff);
			}
		}, $tariffs);

		$maxQuota = array_reduce($quotaCandidates, array($this, 'maxQuota'), "3 GB");

		// Return the max quota limit as human readable unit
		// as it is standard in Nextcloud
		return $maxQuota;
	}
}
