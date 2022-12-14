<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCP\ILogger;

function max_quota($carry, $item)
{
    $carry_size = OC_Helper::computerFileSize($carry);
    $item_size = OC_Helper::computerFileSize($item);
    if ($item_size > $carry_size) {
        return $item;
    } else {
        return $carry;
    }
}

class TariffRules
{
    // NextMagentaCLoud current tariffs
    public const NMC_RATE_NONE = '0 B';
    public const NMC_RATE_FREE3 = '3 GB';
    public const NMC_RATE_FREE10 = '10 GB';
    public const NMC_RATE_S15 = '15 GB';
    public const NMC_RATE_S25 = '25 GB';
    public const NMC_RATE_M100 = '100 GB';
    public const NMC_RATE_L500 = '500 GB';
    public const NMC_RATE_XL1 = '1 TB';
    public const NMC_RATE_XXL5 = '5 TB';

    /** @var ILogger */
    private $logger;

    public function __construct(ILogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Central rule to derive displayname
     * consistently from SAM/SLUP fields
     */
    public function deriveDisplayname(object $claims)
    {
        // TODO: temporary solution
        if (property_exists($claims, 'urn:telekom.com:mainEmail')) {
            $rawDisplayName = $claims->{'urn:telekom.com:mainEmail'};
        } else {
            $rawDisplayName = $claims->{'urn:telekom.com:extMail'};
        }

        if ($rawDisplayName != null) {
            return strstr($rawDisplayName, '@', true);
        }
    }

    /**
     * Central rule to derive tariff from flags
     */
    public function deriveQuota(object $rateFlags)
    {
        // NMC read-only quota
        $applicable_rates = array(
            // legacy rates
            $this->rate_noflags($rateFlags),
            $this->rate_free10($rateFlags),
            $this->rate_s25($rateFlags),
            // active free rates
            $this->rate_free3($rateFlags),
            $this->rate_s15($rateFlags),
            // active paid rates
            $this->rate_m100($rateFlags),
            $this->rate_l500($rateFlags),
            $this->rate_xl1($rateFlags),
            $this->rate_xxl5($rateFlags)
        );
        $max_quota = array_reduce($applicable_rates, [TariffRules::class, 'max_quota'], self::NMC_RATE_FREE3);
        return $max_quota;
    }


    protected static function max_quota($carry, $item)
    {
        $carry_size = \OC_Helper::computerFileSize($carry);
        $item_size = \OC_Helper::computerFileSize($item);
        if ($item_size > $carry_size) {
            return $item;
        } else {
            return $carry;
        }
    }

    /**
     * There are old customer that have booked MagentaCloud but no flags at all
     */
    protected function rate_noflags($rateFlags): string
    {
        // we have to check all flags for beeing not set to avoid side effect.
        // IMPORTANT: Make sure that you enlarge the list if new tariffs come in
        if ((!property_exists($rateFlags, 'urn:telekom.com:f048') || ($rateFlags->{'urn:telekom.com:f048'} == "0")) &&
            (!property_exists($rateFlags, 'urn:telekom.com:f460') || ($rateFlags->{'urn:telekom.com:f460'} == "0")) &&
            (!property_exists($rateFlags, 'urn:telekom.com:f049') || ($rateFlags->{'urn:telekom.com:f049'} == "0")) &&
            (!property_exists($rateFlags, 'urn:telekom.com:f467') || ($rateFlags->{'urn:telekom.com:f467'} == "0")) &&
            (!property_exists($rateFlags, 'urn:telekom.com:f468') || ($rateFlags->{'urn:telekom.com:f468'} == "0")) &&
            (!property_exists($rateFlags, 'urn:telekom.com:f469') || ($rateFlags->{'urn:telekom.com:f469'} == "0")) &&
            (!property_exists($rateFlags, 'urn:telekom.com:f471') || ($rateFlags->{'urn:telekom.com:f471'} == "0")) &&
            (!property_exists($rateFlags, 'urn:telekom.com:f051') || ($rateFlags->{'urn:telekom.com:f051'} == "0"))) {
            return self::NMC_RATE_S25;
        }
        return self::NMC_RATE_NONE; // no default quota, read-only if nothing is booked and access is given
    }


    /**
     * Detect Free3 rate from Telekom feature flags
     */
    protected function rate_free3($rateFlags): string
    {
        // free 3 is either explicitly set or the default minimum
        if (property_exists($rateFlags, 'urn:telekom.com:f048') && ($rateFlags->{'urn:telekom.com:f048'} == "1")) {
            return self::NMC_RATE_FREE3;
        }
        return self::NMC_RATE_NONE; // no default quota, read-only if nothing is booked and access is given
    }

    /**
     * Detect Free10 rate from Telekom feature flags
     */
    protected function rate_free10($rateFlags): string
    {
        if (property_exists($rateFlags, 'urn:telekom.com:f460') && ($rateFlags->{'urn:telekom.com:f460'} == "1")) {
            return self::NMC_RATE_FREE10;
        }
        return self::NMC_RATE_NONE;
    }

    /**
     * Detect (old) Magenta S15 rate from Telekom feature flags
     */
    protected function rate_s15($rateFlags): string
    {
        if (property_exists($rateFlags, 'urn:telekom.com:f049') && ($rateFlags->{'urn:telekom.com:f049'} == "1")) {
            return self::NMC_RATE_S15;
        }
        return self::NMC_RATE_NONE;
    }

    /**
     * Detect (old) Magenta S25 rate from Telekom feature flags
     */
    protected function rate_s25($rateFlags): string
    {
        if (property_exists($rateFlags, 'urn:telekom.com:f467') && ($rateFlags->{'urn:telekom.com:f467'} == "1")) {
            return self::NMC_RATE_S25;
        }
        return self::NMC_RATE_NONE;
    }

    /**
     * Detect Magenta M 100GB rate from Telekom feature flags
     */
    protected function rate_m100($rateFlags): string
    {
        if (property_exists($rateFlags, 'urn:telekom.com:f468') && (strcmp($rateFlags->{'urn:telekom.com:f468'}, "1") == 0)) {
            return self::NMC_RATE_M100;
        }
        return self::NMC_RATE_NONE;
    }

    /**
     * Detect Magenta L 500GB rate from Telekom feature flags
     */
    protected function rate_l500($rateFlags): string
    {
        if (property_exists($rateFlags, 'urn:telekom.com:f469') && (strcmp($rateFlags->{'urn:telekom.com:f469'}, "1") == 0)) {
            return self::NMC_RATE_L500;
        }
        return self::NMC_RATE_NONE;
    }

    /**
     * Detect Magenta XL 1TBrate from Telekom feature flags
     */
    protected function rate_xl1($rateFlags): string
    {
        if (property_exists($rateFlags, 'urn:telekom.com:f471') && ($rateFlags->{'urn:telekom.com:f471'} == "1")) {
            return self::NMC_RATE_XL1;
        }
        return self::NMC_RATE_NONE;
    }

    /**
     * Detect Magenta XXL 5TBrate from Telekom feature flags
     */
    protected function rate_xxl5($rateFlags): string
    {
        if (property_exists($rateFlags, 'urn:telekom.com:f051') && ($rateFlags->{'urn:telekom.com:f051'} == "1")) {
            return self::NMC_RATE_XXL5;
        }
        return self::NMC_RATE_NONE;
    }
}
