<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCA\NextMagentaCloudProvisioning\User\NmcUserService;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\User;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;

class UserAccountRules {

	/** @var ILogger */
	private $logger;

	/** @var IConfig */
	private $config;

	/** @var NmcUserService */
	private $nmcUserService;

    /** @var IUserManager */
    private $userManager;

    /** @var ProviderMapper */
    private $oidcProviderMapper;

	public function __construct(IConfig $config,
                                ILogger        $logger,
                                NmcUserService $nmcUserService,
                                IUserManager   $userManager,
                                ProviderMapper $oidcProviderMapper)
    {
		$this->config = $config;
		$this->logger = $logger;
		$this->nmcUserService = $nmcUserService;
        $this->userManager = $userManager;
        $this->oidcProviderMapper = $oidcProviderMapper;
    }

	/**
	 * Check whether NextMagentaCloud product is booked on customer.
	 */
	public function isBooked($claims) {
		if (property_exists($claims, 'urn:telekom.com:f556') && ($claims->{'urn:telekom.com:f556'} == "1")) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether NextMagentaCloud product is booked on customer.
	 */
	public function isTelekomPreserveProcess($claims) {
		if (property_exists($claims, 'urn:telekom.com:f734') && ($claims->{'urn:telekom.com:f734'} == "1")) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether NextMagentaCloud product is requested
	 * by an Over The Top customer
	 */
	public function isOTTCustomer($claims) {
		if (property_exists($claims, 'urn:telekom.com:usta') && ($claims->{'urn:telekom.com:usta'} == "1")) {
			return true;
		}
		return false;
	}

	/**
	 * Check whether NextMagentaCloud product is requested
	 * by an Access Customer
	 */
	public function isAccessCustomer($claims) {
		if (property_exists($claims, 'urn:telekom.com:usta') && ($claims->{'urn:telekom.com:usta'} == "3")) {
			return true;
		}
		return false;
	}


	/**
	 * Check whether NextMagentaCloud product is blocked
	 */
	public function isLocked($claims) {
		if (property_exists($claims, 'urn:telekom.com:s556') && (strcmp($claims->{'urn:telekom.com:s556'}, "1") == 0)) {
			return true;
		}
		return false;
	}

	/**
	 * Derive withdraw date from token claims
	 */
	public function withdrawDate($claims) : \DateTime {
		if (property_exists($claims, 'changeTime')) {
			return \DateTime::createFromFormat(\DateTimeInterface::ISO8601, $claims->changeTime);
		} elseif (property_exists($claims, 'auth_time')) {
			$withdrawTime = new \DateTime();
			$withdrawTime->setTimestamp(intval($claims->auth_time));
			return $withdrawTime;
		} else {
			return new \DateTime(); // now
		}
	}

    /**
     * @param string $displayname
     * @param string $testParam
     * @return bool
     */
    public function isTestAccount(string $displayname, string $testParam = "-test", string $explodeParam = "@"): bool
    {
        $explode = (str_contains($explodeParam, $displayname)) ? explode($explodeParam, $displayname) : $displayname;
        if (is_array($explode)) {
            return str_contains($explode[0], $testParam);
        } else {
            return str_contains($explode, $testParam);
        }
    }

	/**
	 *
	 * You can adopt the redirect URLs for "Telekom erhalten" with:
	 * `sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userpreserveurl --value 'https://telekom.example.com/'
	 *
	 * and the redirect for general decommissioned accounts with:
	 * `sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userratesurl --value "https://cloud.telekom-dienste.de/tarife"`
	 */
	public function deriveAccountState(string $uid, ?string $displayname, ?string $mainEmail,
                                       string $quota, object $claims, bool $create = true, $providerName = 'Telekom'): array
    {
		$this->logger->info("PROV {$uid}: Check user existence");
        $this->logger->debug("Account change event: " . json_encode(get_object_vars($claims)));
        $this->logger->debug("Provider {$uid}: " . $providerName);
        $config = $this->config->getSystemValue('nmc_provisioning', [
            'slup_test_account_check' => true,
            'slup_test_account_name' => '-test',
            'slup_test_account_explode' => '@'
        ]);
        if ($user = $this->nmcUserService->userExists($providerName, $uid, true)) {
			$this->logger->info("PROV {$uid}: Modify existing");
            return $this->deriveExistingAccountState($user, $displayname, $mainEmail, $quota, $claims, $providerName);
        } elseif ($create || $config['slup_test_account_check'] &&
            $this->isTestAccount($displayname,$config['slup_test_account_name'],$config['slup_test_account_explode'])) {
			$this->logger->info("PROV {$uid}: Create");
            return $this->deriveNewAccountState($uid, $displayname, $mainEmail, $quota, $claims, $providerName);
		}else{
            $this->logger->info("PROV {$uid}: No create");
            return array('allowed' => true, 'reason' => 'No create - please login with the user', 'changed' => true);
        }
	}

	/**
	 * The flag evaluation behaves different if a user does not exist and has to be created.
	 *
	 * In many negative cases, no user account is created at all.
	 */
	protected function deriveNewAccountState(string $uid, ?string $displayname, ?string $mainEmail,
                                             string     $quota, object $claims, $providerName) : array {
		if (is_null($displayname)) {
			$this->logger->error("{$uid}: New user without displayName");
			return array('allowed' => false, 'reason' => 'No displayname no new account', 'changed' => false);
		}

		if ($this->isLocked($claims)) {
			// user is locked due to abuse
			$this->logger->info("{$uid}: New user with lock state, no user created");
			return array('allowed' => false, 'reason' => 'Locked no new account', 'changed' => false);
		}

		if (!$this->isBooked($claims)) {
			$this->logger->info("{$uid}: New user without MagentaCloud tariff, no user created");
			$withdrawUrl = $this->config->getAppValue('nmcprovisioning', 'userwithdrawurl',
				"https://cloud.telekom-dienste.de/tarife");
			return array('allowed' => false, 'reason' => 'No tariff no new account', 'changed' => false, 'redirect' => $withdrawUrl);
		}

        $this->nmcUserService->create($providerName, $uid, $displayname, $mainEmail, $quota);
		$this->logger->info("{$uid}: New user created");
		return array('allowed' => true, 'reason' => 'Created', 'changed' => true);
	}

	/**
	 * The flag evaluation behaves different if a user does not exist and has to be created.
	 *
	 * Many negative cases impact account only on update.
	 */
	public function deriveExistingAccountState(User|IUser $user, ?string $displayname, ?string $mainEmail,
		string $quota, object $claims, $providerName = 'Telekom') : array {
		if ($this->isLocked($claims)) {
			// user is locked due to abuse
			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);
			$this->logger->info("{$user->getUID()}: User locked");
			return array('allowed' => false, 'reason' => 'Locked', 'changed' => true);
		}

		if ($this->isBooked($claims)) {
			// user may has been marked for deletion before re-vitalizing account
			$this->nmcUserService->unmarkDeletion($user->getUID());
			$deletionMark = $this->nmcUserService->getDeletionDateTime($user->getUID());
			// check deletion mark and log error in case
			if ($deletionMark == null) {
				$this->logger->info("{$user->getUID()}: Deletion mark removed.");
			} else {
				$this->logger->error("{$user->getUID()}: Deletion active after reactivation for " . $deletionMark->format(\DateTimeInterface::ISO8601));
			}

			// update case
			// user is active and gets update
            //TODO Check is update required????
			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, true);
			return array('allowed' => true, 'reason' => 'Updated', 'changed' => true);
		} else {
			$withdrawDate = $this->withdrawDate($claims);
			$deletionDate = $this->nmcUserService->markDeletion($user->getUID(), $withdrawDate);
			$this->logger->info("{$user->getUID()}: Withdrawn at " . $withdrawDate->format(\DateTimeInterface::ISO8601) .
								", deletion=" . $deletionDate->format(\DateTimeInterface::ISO8601));
			// lock and latest update of user data
			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);

			if ($this->isTelekomPreserveProcess($claims)) {
				$redirect = $this->config->getAppValue('nmcprovisioning', 'userpreserveurl',
					'https://telekom.example.com/');
			} elseif ($this->isOTTCustomer($claims)) {
				$redirect = $this->config->getAppValue('nmcprovisioning', 'userotturl',
					'https://telekom.example.com/');
			} elseif ($this->isAccessCustomer($claims)) {
				$redirect = $this->config->getAppValue('nmcprovisioning', 'useraccessurl',
					'https://telekom.example.com/');
			} else {
				$redirect = $this->config->getAppValue('nmcprovisioning', 'userwithdrawurl',
					'https://cloud.telekom-dienste.de/tarife');
			}

			return array('allowed' => false, 'reason' => 'Withdrawn', 'changed' => true, 'redirect' => $redirect);
		}
	}
}
