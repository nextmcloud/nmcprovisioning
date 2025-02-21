<?php

namespace OCA\NextMagentaCloudProvisioning\User;

use OC\Authentication\Token\IProvider;
use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCA\NextMagentaCloudProvisioning\Rules\GroupTariffMapping;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\User;
use OCA\UserOIDC\Db\UserMapper;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

// classes from user_oidc app

class NmcUserService {

	/** @var IUserManager */
	private $userManager;

	/** @var IAccountManager */
	private $accountManager;

	/** @var LoggerInterface */
	private $logger;

	/** @var IConfig */
	private $config;

	/** @var UserMapper */
	private $oidcUserMapper;

	/** @var ProviderMapper */
	private $oidcProviderMapper;

	/** @var IProvider */
	protected $tokenProvider;

	/** @var ISecureRandom */
	private $random;

	private GroupTariffMapping $groupTariffMapping;

	private IGroupManager $groupManager;

	public function __construct(IUserManager     $userManager,
		IAccountManager  $accountManager,
		LoggerInterface  $logger,
		IConfig          $config,
		UserMapper       $oidcUserMapper,
		ProviderMapper   $oidcProviderMapper,
		IGroupManager    $groupManager) {
		$this->groupTariffMapping = new GroupTariffMapping($config);
		$this->userManager = $userManager;
		$this->accountManager = $accountManager;
		$this->logger = $logger;
		$this->config = $config;
		$this->oidcUserMapper = $oidcUserMapper;
		$this->oidcProviderMapper = $oidcProviderMapper;
		$this->groupManager = $groupManager;
	}

	/**
	 * Find OpenId connect provider id case-insensitive by name.
	 */
	public function findProviderByIdentifier(string $provider) {
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
	protected function computeUserId(string $providerId, string $username, bool $id4me = false) {
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
	public function findUser(string $provider, string $username): IUser {
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
	 * Check for OpenId user existence
	 */
	public function userExists(string $provider, string $username, bool $returnUser = false) {
		try {
			$user = $this->findUser($provider, $username);

			if ($returnUser) {
				return $user;
			}

			return true;
		} catch (NotFoundException $eNotFound) {
			return $returnUser ? null : false;
		}
	}

	/**
	 *  Set migration flag to user settings $user->getUID()
	 */
	public function setMigrationFlag($userId, bool $flag) {
		$this->config->setUserValue($userId, "nmcuser_oidc", 'migrated', $flag ? 1 : 0);
	}

	/**
	 *  get migration flag to user settings
	 */
	public function getMigrationFlag($userId) {
		return $this->config->getUserValue($userId, "nmcuser_oidc", 'migrated', 0) == 1 ? true : false;
	}

	/**
	 *  Set deletion timestamp for delayed deletion
	 *  It is computed as withdrawDate + userretention (atm withdrawDate + 60D)
	 * `userretention` appvalue is taken as the retention period if set,
	 * given as ISO8601/PHP DateInterval formatted string, with a default of "P60D"
	 * It can be set with:
	 * `sudo -u www-data php /var/www/nextcloud/occ config:app:set nmcprovisioning userretention --value P60DT1H`
	 * @return the computed deletion date
	 */
	public function markDeletion(string $userId, \DateTime $withdrawTime): \DateTime {
		$deletionDate = clone $withdrawTime;
		$retention = new \DateInterval($this->config->getAppValue('nmcprovisioning', 'userretention', "P60D"));
		$deletionDate->add($retention);
		$this->config->setUserValue($userId, Application::APP_ID, 'deletion', $deletionDate->getTimestamp());
		return $deletionDate;
	}

	/**
	 *  Set withdraw timestamp for delayed deletion
	 */
	public function unmarkDeletion(string $userId) {
		$this->config->deleteUserValue($userId, Application::APP_ID, 'deletion');
	}

	/**
	 *  Get planned deletion date, or null if none.
	 */
	public function getDeletionDateTime(string $userId): ?\DateTime {
		$delTs = $this->config->getUserValue($userId, Application::APP_ID, 'deletion', null);
		if ($delTs != null) {
			$delDate = new \DateTime();
			$delDate->setTimestamp($delTs);
			return $delDate;
		} else {
			return null;
		}
	}

	/**
	 * Get openid user data based on username in id system or
	 * by the generic hash id used by NextCloud user_oidc
	 * with priority to the username in OpenID system.
	 */
	public function find(string $provider, string $username) {
		try {
			$user = $this->findUser($provider, $username);
			$userAccount = $this->accountManager->getAccount($user);
			return [
				'id' => $user->getUID(),
				'displayname' => $user->getDisplayName(),
				'email' => $user->getEmailAddress(),
				'altemail' => $userAccount->getProperty(IAccountManager::PROPERTY_ADDRESS)->getValue(), // tmp location only
				'quota' => $user->getQuota(),
				'enabled' => $user->isEnabled(),
				'migrated' => $this->getMigrationFlag($user->getUID())
			];
		} catch (DoesNotExistException|MultipleObjectsReturnedException $eNotFound) {
			throw new NotFoundException($eNotFound->getMessage());
		}
	}

	/**
	 * This method only delivers ids/usernames of OpenID connect users
	 */
	public function findAll(string $provider, string $pattern = null, ?int $limit = null, ?int $offset = null) {
		$providerId = $this->findProviderByIdentifier($provider); // check provider although it is not further used later
		//$users = $this->oidcUserMapper->find("", $limit, $offset);

		if ($pattern === null) {
			$users = $this->userManager->search("", $limit, $offset);
		} else {
			$users = $this->userManager->search($pattern, $limit, $offset);
		}

		return array_keys(array_filter($users, function ($user) {
			return ((strlen($user) == 24) && is_numeric($user)) ? true : false;
		}, ARRAY_FILTER_USE_KEY));
	}

	/**
	 * Encapsulation
	 */
	protected function createOidcUser(string $providerId, string $username, string $displayname) {
		// old way with hashed names only:
		// return $this->oidcUserMapper->getOrCreate($providerId, $username);
		$userId = $this->computeUserId($providerId, $username);
		$user = new User();
		$user->setUserId($userId);
		$this->logger->debug("PROV displayname");
		$user->setDisplayName($displayname);
		return $this->oidcUserMapper->insert($user);
	}

	protected function createAccountUser(IUser $user, $email, string $quota, bool $enabled) {
		/*if ($altemail !== null) {
			  $this->logger->debug("PROV altemail");
			  $userAccount = $this->accountManager->getAccount($user);
			  $userAccount->setProperty(IAccountManager::PROPERTY_ADDRESS, $altemail,
				  IAccountManager::SCOPE_PRIVATE, IAccountManager::VERIFIED);
			  $this->accountManager->updateAccount($userAccount);
		  }*/

		if ($email !== null) {
			$this->logger->debug("PROV email");
			$user->setEMailAddress($email);
		}

		$this->logger->debug("PROV quota");
		$user->setQuota($quota);
		$this->autoGroupMatch($user, $quota);
		$this->logger->debug("PROV enable");
		$user->setEnabled($enabled);
		/*$this->logger->debug("PROV migration flag");
		$this->setMigrationFlag($user->getUID(), $migrated);*/
	}


	/**
	 * Create a compliant user for
	 */
	public function create(string $provider,
		string $username,
		string $displayname,
		$email = null,
		string $quota = "3 GB",
		bool   $enabled = true) {
		$providerId = $this->findProviderByIdentifier($provider);
		/*if ($this->userExists($providerId, $username)) {
			throw new UserExistException("OpenID user " . $provider . ":" . $username . " already exists!");
		}*/

		//Create oidc user
		$this->logger->debug("PROV create db user");
		$oidcUser = $this->createOidcUser($providerId, $username, $displayname);

		//Create account user
		$this->logger->debug("PROV standard account");
		$user = $this->userManager->get($oidcUser->getUserId());
		$this->logger->info("UserID: ".$oidcUser->getUserId());
		$this->createAccountUser($user, $email, $quota, $enabled);

		return [
			'id' => $oidcUser->getUserId()
		];
	}

	protected function updateUserSettings(IUser $user, string|null $email, string|null $quota, bool $enabled) {
		if (!empty($email) &&
			strtolower($email) !== strtolower($user->getEMailAddress())) {
			$this->logger->debug("PROV email {$user->getUID()} old: {$user->getEMailAddress()} new: {$email}");
			$user->setEMailAddress($email);
		}

		//Its enough to check the string value from quota, the group mapping is matching with exact quota string
		if (!is_null($quota) &&
			$quota !== $user->getQuota()) {
			$this->logger->debug("PROV quota {$user->getUID()} old: {$user->getQuota()} new: {$quota}");
			$user->setQuota($quota);
			$this->autoGroupMatch($user, $quota);
		}

		if ($enabled !== $user->isEnabled()) {
			$user->setEnabled($enabled);
		}
	}

	protected function updateOidcUser(User $oidcUser, string|null $displayname) {
		//Check is displayname changed
		if (!is_null($displayname) &&
			$displayname !== $oidcUser->getDisplayName()) {
			$this->logger->debug("PROV displayname {$oidcUser->getUserId()} old: {$oidcUser->getDisplayName()} new: {$displayname}");
			$oidcUser->setDisplayName($displayname);
			$this->oidcUserMapper->update($oidcUser);
		}
	}

	public function update(User|IUser $user,
		$displayname = null,
		$email = null,
		$quota = null,
		bool   $enabled = null) {
		$this->logger->debug("PROV standard account");
		$oidcUser = $this->oidcUserMapper->getUser($user->getUID());
		//		$userAccount = $this->accountManager->getAccount($user);

		/*		if ($altemail !== null) {
					$this->logger->debug("PROV altemail");
					$userAccount->setProperty(IAccountManager::PROPERTY_ADDRESS, $altemail,
						IAccountManager::SCOPE_PRIVATE, IAccountManager::VERIFIED);
					$this->accountManager->updateAccount($userAccount);
				}*/

		/*		if ($migrated !== null) {
					$this->logger->debug("PROV migration flag");
					$this->setMigrationFlag($user->getUID(), $migrated);
				}*/

		$this->updateOidcUser($oidcUser, $displayname);

		$this->updateUserSettings($user, $email, $quota, $enabled);

		$this->logger->debug("PROV read state");
		$userState = [
			'id' => $user->getUID(),
			'displayname' => $user->getDisplayName(),
			'email' => $user->getEmailAddress(),
			'quota' => $user->getQuota(),
			'enabled' => $user->isEnabled(),
			'migrated' => $this->getMigrationFlag($user->getUID()),
		];
		$deletionDate = $this->getDeletionDateTime($user->getUID());
		if (!is_null($deletionDate)) {
			$userState['deletion'] = $deletionDate->format(\DateTimeInterface::ISO8601);
		}
		return $userState;
	}

	private function autoGroupMatch($user, $quota) {
		$this->resetUserGroups($user);
		foreach ($this->groupTariffMapping->getGroupMapping() as $group) {
			if ($group['space_limit'] === $quota) {
				$group = $this->groupManager->get($group['name']);
				$group?->addUser($user);
			}
		}
	}

	private function resetUserGroups($user) {
		foreach ($this->groupManager->getUserGroups($user) as $group) {
			foreach ($this->groupTariffMapping->getGroupMapping() as $mapping) {
				if ($mapping['name'] === $group->getGID()) {
					$group = $this->groupManager->get($group->getGID());
					$group?->removeUser($user);
				}
			}
		}
	}
}
