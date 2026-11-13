<?php

namespace OCA\NextMagentaCloudProvisioning\Rules;

use OCA\NextMagentaCloudProvisioning\User\NmcUserService;
use OCA\UserOIDC\Db\ProviderMapper;
use OCA\UserOIDC\Db\User;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class UserAccountRules {

	private const BOOKING_CLAIM = 'urn:telekom.com:f556';
	private const LOCK_CLAIM = 'urn:telekom.com:s556';
	private const PRESERVE_CLAIM = 'urn:telekom.com:f734';
	private const USTA_CLAIM = 'urn:telekom.com:usta';
	private const CHANGE_TIME_CLAIM = 'changeTime';

	private IConfig $config;
	private LoggerInterface $logger;
	private NmcUserService $nmcUserService;
	private IUserManager $userManager;
	private ProviderMapper $oidcProviderMapper;

	public function __construct(
		IConfig $config,
		LoggerInterface $logger,
		NmcUserService $nmcUserService,
		IUserManager $userManager,
		ProviderMapper $oidcProviderMapper
	) {
		$this->config = $config;
		$this->logger = $logger;
		$this->nmcUserService = $nmcUserService;
		$this->userManager = $userManager;
		$this->oidcProviderMapper = $oidcProviderMapper;
	}

	// true = booked, false = withdrawn, null = claim missing.
	public function getBookingState(object $claims): ?bool {
		if (!property_exists($claims, self::BOOKING_CLAIM)) {
			return null;
		}
		return (string)$claims->{self::BOOKING_CLAIM} === '1';
	}

	public function isBooked(object $claims): bool {
		return $this->getBookingState($claims) === true;
	}

	public function isTelekomPreserveProcess(object $claims): bool {
		return property_exists($claims, self::PRESERVE_CLAIM) && (string)$claims->{self::PRESERVE_CLAIM} === '1';
	}

	public function isOTTCustomer(object $claims): bool {
		return property_exists($claims, self::USTA_CLAIM) && (string)$claims->{self::USTA_CLAIM} === '1';
	}

	public function isAccessCustomer(object $claims): bool {
		return property_exists($claims, self::USTA_CLAIM) && (string)$claims->{self::USTA_CLAIM} === '3';
	}

	public function isLocked(object $claims): bool {
		return property_exists($claims, self::LOCK_CLAIM) && (string)$claims->{self::LOCK_CLAIM} === '1';
	}

	// changeTime is authoritative. Never use auth_time for retention.
	public function withdrawDate(object $claims): ?\DateTimeImmutable {
		if (!property_exists($claims, self::CHANGE_TIME_CLAIM)) {
			return null;
		}

		$value = trim((string)$claims->{self::CHANGE_TIME_CLAIM});
		if ($value === '') {
			return null;
		}

		$formats = [
			'Y-m-d\TH:i:s.vP',
			'Y-m-d\TH:i:s.uP',
			\DateTimeInterface::ATOM,
			\DateTimeInterface::ISO8601,
			'Y-m-d\TH:i:s.v\Z',
			'Y-m-d\TH:i:s\Z',
		];

		foreach ($formats as $format) {
			$date = \DateTimeImmutable::createFromFormat($format, $value);
			$errors = \DateTimeImmutable::getLastErrors();

			if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
				return $date;
			}
		}

		return null;
	}

	public function isTestAccount(string $displayname, string $testParam = '-test', string $explodeParam = '@'): bool {
		if ($testParam === '') {
			return false;
		}

		$name = $displayname;
		if ($explodeParam !== '' && str_contains($displayname, $explodeParam)) {
			$name = explode($explodeParam, $displayname, 2)[0];
		}

		return str_contains($name, $testParam);
	}

	public function deriveAccountState(
		string $uid,
		?string $displayname,
		?string $mainEmail,
		string $quota,
		object $claims,
		bool $create = true,
		string $providerName = 'Telekom'
	): array {
		$this->logger->info("PROV {$uid}: Check user existence");
		$this->logger->debug("PROV {$uid}: Provider", ['provider' => $providerName]);

		$defaults = [
			'slup_test_account_check' => true,
			'slup_test_account_name' => '-test',
			'slup_test_account_explode' => '@',
		];

		$config = $this->config->getSystemValue('nmc_provisioning', $defaults);
		$config = is_array($config) ? array_replace($defaults, $config) : $defaults;

		$user = $this->nmcUserService->userExists($providerName, $uid, true);
		if ($user) {
			$this->logger->info("PROV {$uid}: Modify existing");
			return $this->deriveExistingAccountState($user, $displayname, $mainEmail, $quota, $claims, $providerName);
		}

		$isTestAccount = (bool)$config['slup_test_account_check']
			&& $displayname !== null
			&& $this->isTestAccount(
				$displayname,
				(string)$config['slup_test_account_name'],
				(string)$config['slup_test_account_explode']
			);

		if ($create || $isTestAccount) {
			$this->logger->info("PROV {$uid}: Create");
			return $this->deriveNewAccountState($uid, $displayname, $mainEmail, $quota, $claims, $providerName);
		}

		$this->logger->info("PROV {$uid}: No create");

		return [
			'allowed' => true,
			'reason' => 'No create - please login with the user',
			'changed' => true,
		];
	}

	protected function deriveNewAccountState(
		string $uid,
		?string $displayname,
		?string $mainEmail,
		string $quota,
		object $claims,
		string $providerName
	): array {
		if ($displayname === null) {
			$this->logger->error("{$uid}: New user without displayName");

			return [
				'allowed' => false,
				'reason' => 'No displayname no new account',
				'changed' => false,
			];
		}

		if ($this->isLocked($claims)) {
			$this->logger->info("{$uid}: New user locked, no user created");

			return [
				'allowed' => false,
				'reason' => 'Locked no new account',
				'changed' => false,
			];
		}

		$bookingState = $this->getBookingState($claims);
		if ($bookingState !== true) {
			$this->logger->info(
				$bookingState === null
					? "{$uid}: New user without tariff claim, no user created"
					: "{$uid}: New user without MagentaCloud tariff, no user created"
			);

			return [
				'allowed' => false,
				'reason' => $bookingState === null ? 'Missing tariff claim no new account' : 'No tariff no new account',
				'changed' => false,
				'redirect' => $this->config->getAppValue(
					'nmcprovisioning',
					'userwithdrawurl',
					'https://cloud.telekom-dienste.de/tarife'
				),
			];
		}

		$this->nmcUserService->create($providerName, $uid, $displayname, $mainEmail, $quota);

		$changeTime = $this->withdrawDate($claims);
		if ($changeTime !== null) {
			$this->nmcUserService->recordAccountChangeTime($uid, $changeTime);
		}

		$this->logger->info("{$uid}: New user created");

		return [
			'allowed' => true,
			'reason' => 'Created',
			'changed' => true,
		];
	}

	public function deriveExistingAccountState(
		User|IUser $user,
		?string $displayname,
		?string $mainEmail,
		string $quota,
		object $claims,
		string $providerName = 'Telekom'
	): array {
		$uid = $user->getUID();
		$bookingState = $this->getBookingState($claims);
		$changeTime = $this->withdrawDate($claims);

		if ($bookingState === true) {
			if ($changeTime !== null && $this->nmcUserService->isStaleAccountChange($uid, $changeTime)) {
				$this->logger->warning("{$uid}: Stale booked event ignored.");

				return [
					'allowed' => $user->isEnabled(),
					'reason' => 'Stale booked event ignored',
					'changed' => false,
				];
			}

			$this->nmcUserService->unmarkDeletion($uid);

			if ($changeTime !== null) {
				$this->nmcUserService->recordAccountChangeTime($uid, $changeTime);
			}

			if ($this->isLocked($claims)) {
				$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);
				$this->logger->info("{$uid}: User booked but locked; deletion cancelled.");

				return [
					'allowed' => false,
					'reason' => 'Locked',
					'changed' => true,
				];
			}

			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, true);

			return [
				'allowed' => true,
				'reason' => 'Updated',
				'changed' => true,
			];
		}

		// Locking alone must never schedule deletion.
		if ($this->isLocked($claims)) {
			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);
			$this->logger->info("{$uid}: User locked");

			return [
				'allowed' => false,
				'reason' => 'Locked',
				'changed' => true,
			];
		}

		// Missing tariff state is not a confirmed withdrawal.
		if ($bookingState === null) {
			$this->nmcUserService->unmarkDeletion($uid);
			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);

			$this->logger->warning("{$uid}: f556 missing. Deletion not scheduled.");

			return $this->withdrawResult('Missing tariff claim - deletion not scheduled', $claims, true);
		}

		// Withdrawal needs a valid authoritative timestamp.
		if ($changeTime === null) {
			$this->nmcUserService->unmarkDeletion($uid);
			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);

			$this->logger->error("{$uid}: Withdrawal without valid changeTime. Deletion not scheduled.");

			return $this->withdrawResult('Withdrawn without valid changeTime - deletion not scheduled', $claims, true);
		}

		if ($this->nmcUserService->isStaleAccountChange($uid, $changeTime)) {
			$this->logger->warning("{$uid}: Stale withdrawal ignored at " . $changeTime->format(\DateTimeInterface::ATOM));

			return [
				'allowed' => $user->isEnabled(),
				'reason' => 'Stale withdrawal event ignored',
				'changed' => false,
			];
		}

		try {
			$deletionDate = $this->nmcUserService->markDeletion($uid, $changeTime);
		} catch (\Throwable $e) {
			$this->nmcUserService->unmarkDeletion($uid);
			$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);

			$this->logger->critical("{$uid}: Failed to schedule deletion safely.", [
				'exception' => $e,
			]);

			return $this->withdrawResult('Deletion scheduling failed safely', $claims, true);
		}

		if ($deletionDate === null) {
			$this->logger->warning("{$uid}: Withdrawal lost race with newer state.");

			return [
				'allowed' => $user->isEnabled(),
				'reason' => 'Stale withdrawal event ignored',
				'changed' => false,
			];
		}

		$this->logger->info(
			"{$uid}: Withdrawn at {$changeTime->format(\DateTimeInterface::ATOM)}, deletion={$deletionDate->format(\DateTimeInterface::ATOM)}"
		);

		$this->nmcUserService->update($user, $displayname, $mainEmail, $quota, false);

		return $this->withdrawResult('Withdrawn', $claims, true);
	}

	private function withdrawResult(string $reason, object $claims, bool $changed): array {
		if ($this->isTelekomPreserveProcess($claims)) {
			$redirect = $this->config->getAppValue('nmcprovisioning', 'userpreserveurl', 'https://telekom.example.com/');
		} elseif ($this->isOTTCustomer($claims)) {
			$redirect = $this->config->getAppValue('nmcprovisioning', 'userotturl', 'https://telekom.example.com/');
		} elseif ($this->isAccessCustomer($claims)) {
			$redirect = $this->config->getAppValue('nmcprovisioning', 'useraccessurl', 'https://telekom.example.com/');
		} else {
			$redirect = $this->config->getAppValue(
				'nmcprovisioning',
				'userwithdrawurl',
				'https://cloud.telekom-dienste.de/tarife'
			);
		}

		return [
			'allowed' => false,
			'reason' => $reason,
			'changed' => $changed,
			'redirect' => $redirect,
		];
	}
}
