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
     * 
     * For random combinations of tariffs where even one of
     * the flaged tariffs can be 0, the case must be explixitly checked
     * and cannot be used as default.
	 */
	private function quotaNoFlags(object $claims, $tariffs) {
        $anyFlagSet = array_reduce($tariffs, function ($carry, $tariff) use ($claims) {
            if (array_key_exists('flag', $tariff) && ($carry == false)) {
                return $this->isQuotaFlagSet($claims, $tariff);
            } else {
                return $carry;
            }
        }, false);

        if (!$anyFlagSet) {
            // make sure that a quota >0 is only delivered
            // for the NOFLAGS case if actually no flags are set
            // Otherwise, all quotas < the NOFLAGS quota will not apply
            return $tariffs['NOFLAGS']['space_limit'];
        } else {
            return "0 B";
        }
	}


	/**
	 * Get the quota if a flag is set
	 * The function does assume that the flag field exists
	 */
	private function isQuotaFlagSet(object $claims, $tariff) {
		$flagname = $tariff['flag'];
		if (property_exists($claims, $flagname) &&
				$claims->{$flagname} === "1") {
			return true;
		} else {
			return false;
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
				return $this->isQuotaFlagSet($claims, $tariff) ? $tariff['space_limit'] : "0 B";
			} else {
                // skip the "no flags" case
                // it is the deafult value of reduce
				return "0 B";
			}
		}, $tariffs);

        $noflagsQuota = $this->quotaNoFlags($claims, $tariffs);
		$maxQuota = array_reduce($quotaCandidates, array($this, 'maxQuota'), $noflagsQuota);

		// Return the max quota limit as human readable unit
		// as it is standard in Nextcloud
		return $maxQuota;
	}
}
