<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCA\NextMagentaCloudProvisioning\User\NmcUserService;
use OCA\NextMagentaCloudProvisioning\User\NotFoundException;
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
     * Find OpenId connect provider id case-insensitive by name.
     */
    public function findProviderByIdentifier(string $provider)
    {
        $providers = $this->oidcProviderMapper->getProviders();
        foreach ($providers as $p) {
            if ((strcasecmp($p->getIdentifier(), $provider) == 0) ||
                (strcmp($p->id, $provider) == 0)) {
                return $p->id;
            }
        }

        throw new NotFoundException("No oidc provider " . $provider);
    }

    /**
     * Imitate zhe userID computation from oidc app
     * id4me is not used/supported yet.
     */
    protected function computeUserId(string $providerId, string $username, bool $id4me = false)
    {
        // old way with hashed names only:
        //if ($id4me) {
        //	return hash('sha256', $providerId . '_1_' . $username);
        //} else {
        //	return hash('sha256', $providerId . '_0_' . $username);
        //}
        if (strlen($username) > 64) {
            return hash('sha256', $username);
        } else {
            return $username;
        }
    }

    /**
     * Find openid user entries based on username in id system or
     * by the generic hash id used by NextCloud user_oidc
     * with priority to the username in OpenID system.
     * @return user object from manager
     */
    public function findUser(string $provider, string $username): IUser
    {
        $providerId = $this->findProviderByIdentifier($provider);
        $oidcUserId = $this->computeUserId($providerId, $username);
        $user = $this->userManager->get($oidcUserId);
        if ($user === null) {
            $user = $this->userManager->get($username);
        }
        if ($user === null) {
            throw new NotFoundException("No user " . $username . ", id=" . $oidcUserId);
        }

        return $user;
    }

	/**
	 *
	 * You can adopt the redirect URLs for "Telekom erhalten" with:
	 * `sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userpreserveurl --value 'https://telekom.example.com/'
	 *
	 * and the redirect for general decommissioned accounts with:
	 * `sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userratesurl --value "https://cloud.telekom-dienste.de/tarife"`
	 */
	public function deriveAccountState(string $uid, ?string $displayname, ?string $mainEmail, ?string $altEmail,
                                       string $quota, object $claims, bool $create = false, $providerName = 'Telekom'): array
    {
		$this->logger->info("PROV {$uid}: Check user existence");
        //if ($this->nmcUserService->userExists($providerName, $uid)) {
        if ($user = $this->findUser($providerName, $displayname)) {
			$this->logger->info("PROV {$uid}: Modify existing");
            return $this->deriveExistingAccountState($user, $displayname, $mainEmail, $altEmail, $quota, $claims, $providerName);
		} else {
            //TODO Check is created set, than create user or create not user
			$this->logger->info("PROV {$uid}: Create");
            return $this->deriveNewAccountState($user, $displayname, $mainEmail, $altEmail, $quota, $claims, $providerName);
		}
	}

	/**
	 * The flag evaluation behaves different if a user does not exist and has to be created.
	 *
	 * In many negative cases, no user account is created at all.
	 */
	protected function deriveNewAccountState(string $uid, ?string $displayname, ?string $mainEmail, ?string $altEmail,
		string $quota, object $claims, $providerName) : array {
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
	public function deriveExistingAccountState(string $uid, ?string $displayname, ?string $mainEmail, ?string $altEmail,
		string $quota, object $claims, $providerName = 'Telekom') : array {
		if ($this->isLocked($claims)) {
			// user is locked due to abuse
			$this->nmcUserService->update($providerName, $uid, $displayname, $mainEmail, $altEmail, $quota, false, false);
			$this->logger->info("{$uid}: User locked");
			return array('allowed' => false, 'reason' => 'Locked', 'changed' => true);
		}

		if ($this->isBooked($claims)) {
			// user may has been marked for deletion before re-vitalizing account
			$this->nmcUserService->unmarkDeletion($uid);
			$deletionMark = $this->nmcUserService->getDeletionDateTime($uid);
			// check deletion mark and log error in case
			if ($deletionMark == null) {
				$this->logger->info("{$uid}: Deletion mark removed.");
			} else {
				$this->logger->error("{$uid}: Deletion active after reactivation for " . $deletionMark->format(\DateTimeInterface::ISO8601));
			}

			// update case
			// user is active and gets update
            //TODO Check is update required????
			$this->nmcUserService->update($providerName, $uid, $displayname, $mainEmail, $altEmail, $quota, false, true);
			return array('allowed' => true, 'reason' => 'Updated', 'changed' => true);
		} else {
			$withdrawDate = $this->withdrawDate($claims);
			$deletionDate = $this->nmcUserService->markDeletion($uid, $withdrawDate);
			$this->logger->info("{$uid}: Withdrawn at " . $withdrawDate->format(\DateTimeInterface::ISO8601) .
								", deletion=" . $deletionDate->format(\DateTimeInterface::ISO8601));
			// lock and latest update of user data
			$this->nmcUserService->update($providerName, $uid, $displayname, $mainEmail, $altEmail, $quota, false, false);

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
