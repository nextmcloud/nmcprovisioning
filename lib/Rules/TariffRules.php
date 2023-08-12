<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use InvalidArgumentException;
use OCA\NextMagentaCloudProvisioning\Logger\ProvisioningLogger;
use OCA\NextMagentaCloudProvisioning\Service\GroupHelper;

class TariffRules
{

    private GroupHelper $groupHelper;

    /** @var ProvisioningLogger */
    private ProvisioningLogger $provisioningLogger;

    private array $displaynameSearch = [
        ['zusa', 'name'],
        ['displayName'],
        ['mainEmail'],
        ['extmail'],
        ['extMail'],
        ['name'],
    ];

    public function __construct(GroupHelper $groupHelper, ProvisioningLogger $provisioningLogger)
    {
        $this->groupHelper = $groupHelper;
        $this->provisioningLogger = $provisioningLogger;
    }

    /**
     * Central rule to derive displayname
     * consistently from SAM/SLUP fields
     */
    public function deriveDisplayname(object $claims): ?string
    {
        $displayname = "";
        foreach ($this->displaynameSearch as $search) {
            foreach ($search as $field) {
                $fieldSearch = 'urn:telekom.com:' . $field;
                if (property_exists($claims, $fieldSearch)) {
                    $displayname .= $claims->{$fieldSearch} . " ";
                }
            }
            if (!empty($displayname)) {
                break;
            }
        }

        if (empty($displayname)) {
            $this->provisioningLogger->warning('Could not derive displayname from claims', ['claims' => $claims]);
            return null;
        }

        return trim($displayname);
    }

    /**
     * Central rule to derive quota
     * consistently from SAM/SLUP fields
     */
    public function deriveQuota(object $rateFlat)
    {
        //Save the quota limit
        $quotaLimit = [];

        //Check if the flag is set and add the quota limit array
        foreach ($this->groupHelper->getGroupMapping() as $quotaGroup) {
            if (property_exists($rateFlat, $quotaGroup['flag']) && $rateFlat->{$quotaGroup['flag']} == "1") {
                $quotaLimit[] = $quotaGroup['space_limit'];
            }
        }

        //Check if no rate then return none
        if (empty($quotaLimit)) {
            $this->provisioningLogger->debug('No rate found, returning NONE');
            return $this->groupHelper->getGroupMapping()["NONE"]['space_limit'];
        }

        $this->provisioningLogger->debug('Found quota', ['quotaLimit' => $quotaLimit]);
        //Return the max quota limit
        return $this->getMaxSize($quotaLimit);
    }

    private function convertToBytes($size): float
    {
        $units = array('B' => 0, 'KB' => 1, 'MB' => 2, 'GB' => 3, 'TB' => 4);
        $parts = explode(' ', $size);
        $number = (float)$parts[0];
        $unit = strtoupper(trim($parts[1]));

        if (!isset($units[$unit])) {
            throw new InvalidArgumentException("Invalid size unit: $unit");
        }

        $bytes = $number * pow(1024, $units[$unit]);
        return $bytes;
    }

    private function getMaxSize($sizes)
    {
        $maxSize = 0;

        foreach ($sizes as $size) {
            $bytes = $this->convertToBytes($size);
            $maxSize = max($maxSize, $bytes);
        }

        return $maxSize;
    }

}
