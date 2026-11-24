<?php

namespace OCA\NextMagentaCloudProvisioning\User;

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
use Psr\Log\LoggerInterface;

class NmcUserService {
	private const KEY_DELETION = 'deletion';
	private const KEY_WITHDRAWAL = 'withdrawal';
	private const KEY_LAST_ACCOUNT_CHANGE = 'last_account_change';

	private const DEFAULT_RETENTION = 'P60D';

	private IUserManager $userManager;
	private IAccountManager $accountManager;
	private LoggerInterface $logger;
	private IConfig $config;
	private UserMapper $oidcUserMapper;
	private ProviderMapper $oidcProviderMapper;
	private GroupTariffMapping $groupTariffMapping;
	private IGroupManager $groupManager;

	public function __construct(IUserManager $userManager,
		IAccountManager $accountManager,
		LoggerInterface $logger,
		IConfig $config,
		UserMapper $oidcUserMapper,
		ProviderMapper $oidcProviderMapper,
		IGroupManager $groupManager) {
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
	 * Find OpenID Connect provider ID case-insensitive by name.
	 */
	public function findProviderByIdentifier(string $provider) {
		$providers = $this->oidcProviderMapper->getProviders();
		foreach ($providers as $p) {
			if (strcasecmp($p->getIdentifier(), $provider) === 0 ||
				strcmp($p->id, $provider) === 0) {
				return $p->id;
			}
		}

		throw new NotFoundException('No oidc provider ' . $provider);
	}

	/**
	 * Compute the user ID in the same way as the OIDC integration.
	 */
	protected function computeUserId(string $providerId, string $username, bool $id4me = false): string {
		if (strlen($username) > 64) {
			return hash('sha256', $username);
		}

		return $username;
	}

	/**
	 * Find OpenID user based on the username in the identity system.
	 */
	public function findUser(string $provider, string $username): IUser {
		$providerId = $this->findProviderByIdentifier($provider);
		$oidcUserId = $this->computeUserId($providerId, $username);
		$user = $this->userManager->get($oidcUserId);

		if ($user === null) {
			$user = $this->userManager->get($username);
		}

		if ($user === null) {
			throw new NotFoundException('No user ' . $username . ', id=' . $oidcUserId);
		}

		return $user;
	}

	/**
	 * Check for OpenID user existence.
	 */
	public function userExists(string $provider, string $username, bool $returnUser = false) {
		try {
			$user = $this->findUser($provider, $username);

			if ($returnUser) {
				return $user;
			}

			return true;
		} catch (NotFoundException) {
			return $returnUser ? null : false;
		}
	}

	public function setMigrationFlag(string $userId, bool $flag): void {
		$this->config->setUserValue($userId, 'nmcuser_oidc', 'migrated', $flag ? 1 : 0);
	}

	public function getMigrationFlag(string $userId): bool {
		return (int)$this->config->getUserValue($userId, 'nmcuser_oidc', 'migrated', 0) === 1;
	}

	/**
	 * Return the newest accepted account-state change timestamp.
	 *
	 * This value deliberately remains after a pending deletion is cancelled,
	 * allowing delayed or out-of-order withdrawal events to be rejected.
	 */
	public function getLastAccountChangeTime(string $userId): ?\DateTimeImmutable {
		return $this->getUserTimestamp($userId, self::KEY_LAST_ACCOUNT_CHANGE);
	}

	/**
	 * Record an account-state change without allowing an older timestamp
	 * to replace a newer one.
	 */
	public function recordAccountChangeTime(string $userId, \DateTimeInterface $changeTime): bool {
		$incoming = \DateTimeImmutable::createFromInterface($changeTime);
		$now = new \DateTimeImmutable();

		if ($incoming > $now) {
			$this->logger->warning("Clamping future account change time to current time for user {$userId}", [
				'userId' => $userId,
				'changeTime' => $incoming->format(\DateTimeInterface::ATOM),
				'now' => $now->format(\DateTimeInterface::ATOM),
			]);
			$incoming = $now;
		}

		$incomingTimestamp = $incoming->getTimestamp();
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
	 * Repeated delivery of the same withdrawal event is idempotent and keeps
	 * the already scheduled deletion date.
	 *
	 * @return \DateTimeImmutable|null Null if the withdrawal event is stale.
	 */
	public function markDeletion(string $userId, \DateTimeInterface $withdrawTime): ?\DateTimeImmutable {
		$withdrawal = \DateTimeImmutable::createFromInterface($withdrawTime);
		$now = new \DateTimeImmutable();

		if ($withdrawal > $now) {
			$this->logger->warning("Clamping future withdrawal time to current time for user {$userId}", [
				'userId' => $userId,
				'withdrawTime' => $withdrawal->format(\DateTimeInterface::ATOM),
				'now' => $now->format(\DateTimeInterface::ATOM),
			]);
			$withdrawal = $now;
		}

		if ($this->isStaleAccountChange($userId, $withdrawal)) {
			$this->logger->warning("Refusing stale withdrawal event for user {$userId}", [
				'userId' => $userId,
				'withdrawTime' => $withdrawal->format(\DateTimeInterface::ATOM),
				'lastAccountChange' => $this->getLastAccountChangeTime($userId)?->format(\DateTimeInterface::ATOM),
			]);
			return null;
		}

		$existingWithdrawal = $this->getUserTimestamp($userId, self::KEY_WITHDRAWAL);
		$existingDeletion = $this->getDeletionDateTime($userId);

		if ($existingWithdrawal !== null &&
			$existingDeletion !== null &&
			$existingWithdrawal->getTimestamp() === $withdrawal->getTimestamp()) {
			return $existingDeletion;
		}

		$retention = $this->getConfiguredInterval('userretention', self::DEFAULT_RETENTION);
		$deletionDate = $now->add($retention);

		if (!$this->recordAccountChangeTime($userId, $withdrawal)) {
			$this->logger->warning("Withdrawal for user {$userId} became stale before it could be stored", [
				'userId' => $userId,
				'withdrawTime' => $withdrawal->format(\DateTimeInterface::ATOM),
			]);
			return null;
		}

		$this->logger->info("Marking user {$userId} for delayed deletion", [
			'userId' => $userId,
			'withdrawTime' => $withdrawal->format(\DateTimeInterface::ATOM),
			'deletionDate' => $deletionDate->format(\DateTimeInterface::ATOM),
			'retention' => $this->config->getAppValue(Application::APP_ID, 'userretention', self::DEFAULT_RETENTION),
		]);

		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_DELETION, $deletionDate->getTimestamp());
		$this->config->setUserValue($userId, Application::APP_ID, self::KEY_WITHDRAWAL, $withdrawal->getTimestamp());

		return $deletionDate;
	}

	/**
	 * Cancel a pending deletion.
	 *
	 * last_account_change deliberately remains stored.
	 */
	public function unmarkDeletion(string $userId): void {
		$deletionDate = $this->getDeletionDateTime($userId);
		$withdrawal = $this->getUserTimestamp($userId, self::KEY_WITHDRAWAL);

		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_DELETION);
		$this->config->deleteUserValue($userId, Application::APP_ID, self::KEY_WITHDRAWAL);

		if ($deletionDate !== null || $withdrawal !== null) {
			$this->logger->info("Cancelled pending deletion for user {$userId}", [
				'userId' => $userId,
				'withdrawTime' => $withdrawal?->format(\DateTimeInterface::ATOM),
				'previousDeletionDate' => $deletionDate?->format(\DateTimeInterface::ATOM),
				'lastAccountChange' => $this->getLastAccountChangeTime($userId)?->format(\DateTimeInterface::ATOM),
			]);
		}
	}

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
		$value = $this->config->getAppValue(Application::APP_ID, $key, $default);

		try {
			return new \DateInterval($value);
		} catch (\Throwable $e) {
			throw new \RuntimeException("Invalid " . Application::APP_ID . " configuration {$key}={$value}", 0, $e);
		}
	}

	/**
	 * Get OpenID user data.
	 */
	public function find(string $provider, string $username) {
		try {
			$user = $this->findUser($provider, $username);
			$userAccount = $this->accountManager->getAccount($user);

			return [
				'id' => $user->getUID(),
				'displayname' => $user->getDisplayName(),
				'email' => $user->getEmailAddress(),
				'altemail' => $userAccount->getProperty(IAccountManager::PROPERTY_ADDRESS)->getValue(),
				'quota' => $user->getQuota(),
				'enabled' => $user->isEnabled(),
				'migrated' => $this->getMigrationFlag($user->getUID()),
			];
		} catch (DoesNotExistException|MultipleObjectsReturnedException $eNotFound) {
			throw new NotFoundException($eNotFound->getMessage());
		}
	}

	/**
	 * Return IDs/usernames of OpenID Connect users.
	 */
	public function findAll(string $provider, ?string $pattern = null, ?int $limit = null, ?int $offset = null) {
		$this->findProviderByIdentifier($provider);
		$users = $this->userManager->search($pattern ?? '', $limit, $offset);

		return array_keys(array_filter($users, static function ($user): bool {
			return strlen($user) === 24 && is_numeric($user);
		}, ARRAY_FILTER_USE_KEY));
	}

	protected function createOidcUser(string $providerId, string $username, string $displayname) {
		$userId = $this->computeUserId($providerId, $username);
		$user = new User();
		$user->setUserId($userId);
		$user->setDisplayName($displayname);

		return $this->oidcUserMapper->insert($user);
	}

	protected function createAccountUser(IUser $user, ?string $email, string $quota, bool $enabled): void {
		$userId = $user->getUID();

		if ($email !== null) {
			$this->logger->debug("PROV email for user {$userId}");
			$user->setEMailAddress($email);
		}

		$this->logger->debug("PROV quota for user {$userId}");
		$user->setQuota($quota);
		$this->autoGroupMatch($user, $quota);

		$this->logger->debug("PROV enable state for user {$userId}");
		$user->setEnabled($enabled);
	}

	public function create(string $provider,
		string $username,
		string $displayname,
		?string $email = null,
		string $quota = '3 GB',
		bool $enabled = true) {
		$providerId = $this->findProviderByIdentifier($provider);

		$this->logger->debug("PROV create db user {$username}");
		$oidcUser = $this->createOidcUser($providerId, $username, $displayname);

		$userId = $oidcUser->getUserId();

		$this->logger->debug("PROV create standard account for user {$userId}");
		$user = $this->userManager->get($userId);

		$this->logger->info("Created/provisioning user {$userId}", [
			'userId' => $userId,
		]);

		$this->createAccountUser($user, $email, $quota, $enabled);

		return [
			'id' => $userId,
		];
	}

	protected function updateUserSettings(IUser $user, ?string $email, ?string $quota, ?bool $enabled): void {
		$userId = $user->getUID();

		if (!empty($email) &&
			strtolower($email) !== strtolower((string)$user->getEMailAddress())) {
			$this->logger->debug("PROV email {$userId} old: {$user->getEMailAddress()} new: {$email}");
			$user->setEMailAddress($email);
		}

		if ($quota !== null && $quota !== $user->getQuota()) {
			$this->logger->debug("PROV quota {$userId} old: {$user->getQuota()} new: {$quota}");
			$user->setQuota($quota);
			$this->autoGroupMatch($user, $quota);
		}

		if ($enabled !== null && $enabled !== $user->isEnabled()) {
			$this->logger->debug(
				"PROV enabled {$userId} old: " . ($user->isEnabled() ? 'true' : 'false') .
				' new: ' . ($enabled ? 'true' : 'false')
			);
			$user->setEnabled($enabled);
		}
	}

	protected function updateOidcUser(User $oidcUser, ?string $displayname): void {
		if ($displayname !== null && $displayname !== $oidcUser->getDisplayName()) {
			$this->logger->debug(
				"PROV displayname {$oidcUser->getUserId()} old: {$oidcUser->getDisplayName()} new: {$displayname}"
			);
			$oidcUser->setDisplayName($displayname);
			$this->oidcUserMapper->update($oidcUser);
		}
	}

	public function update(User|IUser $user,
		$displayname = null,
		$email = null,
		$quota = null,
		?bool $enabled = null) {
		$userId = $user->getUID();

		$this->logger->debug("PROV standard account for user {$userId}");
		$oidcUser = $this->oidcUserMapper->getUser($userId);

		$this->updateOidcUser($oidcUser, $displayname);
		$this->updateUserSettings($user, $email, $quota, $enabled);

		$this->logger->debug("PROV read state for user {$userId}");

		$userState = [
			'id' => $userId,
			'displayname' => $user->getDisplayName(),
			'email' => $user->getEmailAddress(),
			'quota' => $user->getQuota(),
			'enabled' => $user->isEnabled(),
			'migrated' => $this->getMigrationFlag($userId),
		];

		$deletionDate = $this->getDeletionDateTime($userId);
		if ($deletionDate !== null) {
			$userState['deletion'] = $deletionDate->format(\DateTimeInterface::ISO8601);
		}

		return $userState;
	}

	private function autoGroupMatch(IUser $user, string $quota): void {
		$this->resetUserGroups($user);

		foreach ($this->groupTariffMapping->getGroupMapping() as $group) {
			if ($group['space_limit'] === $quota) {
				$matchedGroup = $this->groupManager->get($group['name']);
				$matchedGroup?->addUser($user);
			}
		}
	}

	private function resetUserGroups(IUser $user): void {
		foreach ($this->groupManager->getUserGroups($user) as $group) {
			foreach ($this->groupTariffMapping->getGroupMapping() as $mapping) {
				if ($mapping['name'] === $group->getGID()) {
					$matchedGroup = $this->groupManager->get($group->getGID());
					$matchedGroup?->removeUser($user);
				}
			}
		}
	}
}
