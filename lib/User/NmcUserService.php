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
	private const KEY_DELETION = 'deletion';
	private const KEY_WITHDRAWAL = 'withdrawal';
	private const KEY_DELETION_MARKED_AT = 'deletion_marked_at';
	private const KEY_LAST_ACCOUNT_CHANGE = 'last_account_change';

	private const DEFAULT_RETENTION = 'P60D';
	private const DEFAULT_SAFETY_DELAY = 'P1D';


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
	 * Return the newest account-state change timestamp accepted for this user.
	 *
	 * This timestamp is deliberately kept when a deletion is cancelled. It is
	 * used to reject delayed/out-of-order withdrawal events later on.
	 */
	public function getLastAccountChangeTime(string $userId): ?\DateTimeImmutable {
		return $this->getUserTimestamp($userId, self::KEY_LAST_ACCOUNT_CHANGE);
	}

	/**
	 * Record an account-state change without allowing an older timestamp to
	 * replace a newer one.
	 */
	public function recordAccountChangeTime(string $userId, \DateTimeInterface $changeTime): bool {
		$incomingTimestamp = $changeTime->getTimestamp();
		$current = $this->getLastAccountChangeTime($userId);

		if ($current !== null && $incomingTimestamp < $current->getTimestamp()) {
			return false;
		}

		if ($current === null || $incomingTimestamp > $current->getTimestamp()) {
			$this->config->setUserValue($userId, Application::APP_ID, self::KEY_LAST_ACCOUNT_CHANGE, $incomingTimestamp);
		}

		return true;
	}

	public function isStaleAccountChange(string $userId, \DateTimeInterface $changeTime): bool {
		$current = $this->getLastAccountChangeTime($userId);

		return $current !== null && $changeTime->getTimestamp() < $current->getTimestamp();
	}

	/**
	 * Mark a user for delayed deletion.
	 *
	 * The effective deletion date is the later of:
	 *  - authoritative withdrawal time + configured retention period
	 *  - first local observation of this withdrawal + safety delay
	 *
	 * The second condition prevents an old/replayed event from becoming
	 * immediately destructive when it reaches this system for the first time.
	 *
	 * @return \DateTimeImmutable|null Null if the withdrawal event is stale.
	 */
	public function markDeletion(string $userId, \DateTimeInterface $withdrawTime): ?\DateTimeImmutable {
		if ($this->isStaleAccountChange($userId, $withdrawTime)) {
			$this->logger->warning('Refusing stale withdrawal event', [
				'userId' => $userId,
				'withdrawTime' => $withdrawTime->format(\DateTimeInterface::ATOM),
				'lastAccountChange' => $this->getLastAccountChangeTime($userId)?->format(\DateTimeInterface::ATOM),
			]);
			return null;
		}

		$withdrawal = \DateTimeImmutable::createFromInterface($withdrawTime);
		$existingWithdrawal = $this->getUserTimestamp($userId, self::KEY_WITHDRAWAL);
		$existingMarkedAt = $this->getUserTimestamp($userId, self::KEY_DELETION_MARKED_AT);

		/*
		 * Repeated delivery of the same event must not keep moving the local
		 * safety window. For a genuinely newer withdrawal event, start a new
		 * observation window.
		 */
		if ($existingWithdrawal !== null && $existingMarkedAt !== null && $existingWithdrawal->getTimestamp() === $withdrawal->getTimestamp()) {
			$markedAt = $existingMarkedAt;
		} else {
			$markedAt = new \DateTimeImmutable();
		}

		$retention = $this->getConfiguredInterval('userretention', self::DEFAULT_RETENTION);
		$safetyDelay = $this->getConfiguredInterval('userdeletionsafetydelay', self::DEFAULT_SAFETY_DELAY);

		$retentionDate = $withdrawal->add($retention);
		$safetyDate = $markedAt->add($safetyDelay);
		$deletionDate = $retentionDate > $safetyDate ? $retentionDate : $safetyDate;

		if (!$this->recordAccountChangeTime($userId, $withdrawal)) {
			$this->logger->warning('Withdrawal became stale before it could be stored', [
				'userId' => $userId,
				'withdrawTime' => $withdrawal->format(\DateTimeInterface::ATOM),
			]);
			return null;
		}

		$this->logger->info('Marking user for delayed deletion', [
			'userId' => $userId,
			'withdrawTime' => $withdrawal->format(\DateTimeInterface::ATOM),
			'markedAt' => $markedAt->format(\DateTimeInterface::ATOM),
			'deletionDate' => $deletionDate->format(\DateTimeInterface::ATOM),
			'retention' => $this->config->getAppValue('nmcprovisioning', 'userretention', self::DEFAULT_RETENTION),
			'safetyDelay' => $this->config->getAppValue('nmcprovisioning', 'userdeletionsafetydelay', self::DEFAULT_SAFETY_DELAY),
		]);

		/*
		 * Write supporting metadata first and the actual deletion marker last.
		 * A partially written state therefore cannot become eligible for the
		 * deletion query.
		 */
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_WITHDRAWAL, $withdrawal->getTimestamp());
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DELETION_MARKED_AT, $markedAt->getTimestamp());
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DELETION, $deletionDate->getTimestamp());

		/*
		 * Narrow the race window with a concurrent reactivation/newer event.
		 * If a newer state appeared while this method was writing, disarm the
		 * deletion immediately. The deletion job performs the same check again.
		 */
		if ($this->isStaleAccountChange($userId, $withdrawal)) {
			$this->logger->warning('Newer account state detected while marking deletion', [
				'userId' => $userId,
			]);
			$this->unmarkDeletion($userId);
			return null;
		}

		return $deletionDate;
	}

	/**
	 * Cancel a pending deletion.
	 *
	 * The actual deletion marker is removed first. This makes an interrupted
	 * cleanup fail safe: the background job no longer sees the user as a
	 * deletion candidate.
	 */
	public function unmarkDeletion(string $userId): void {
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_DELETION);
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_WITHDRAWAL);
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_DELETION_MARKED_AT);
	}

	/**
	 * Get planned deletion date, or null if none.
	 */
	public function getDeletionDateTime(string $userId): ?\DateTimeImmutable {
		return $this->getUserTimestamp($userId, self::KEY_DELETION);
	}

	private function getUserTimestamp(string $userId, string $key): ?\DateTimeImmutable {
		$value = $this->config->getUserValue($userId, Application::APP_ID, $key, null);

		if ($value === null || $value === '' || !is_numeric($value)) {
			return null;
		}

		$timestamp = (int)$value;
		if ($timestamp <= 0) {
			return null;
		}

		return (new \DateTimeImmutable())->setTimestamp($timestamp);
	}

	private function getConfiguredInterval(string $key, string $default): \DateInterval {
		$value = $this->config->getAppValue('nmcprovisioning', $key, $default);

		try {
			return new \DateInterval($value);
		} catch (\Throwable $e) {
			throw new \RuntimeException("Invalid nmcprovisioning configuration {$key}={$value}", 0, $e);
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
		if (!is_null($quota) && $quota !== $user->getQuota()) {
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
