<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\Db;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class UserQueries {
	private const DELETION_KEYS = [
		'deletion',
		'withdrawal',
		'deletion_marked_at',
	];

	/** @var IDBConnection */
	private $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * Find the oldest users whose deletion date has been reached.
	 *
	 * @return string[]
	 */
	public function findDeletions(\DateTimeInterface $refDate, ?int $limit = null): array {
		$refTs = $refDate->getTimestamp();

		$qb = $this->db->getQueryBuilder();
		$qb->select('userid')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('deletion')))
			->andWhere($qb->expr()->lte('configvalue', $qb->createNamedParameter($refTs, IQueryBuilder::PARAM_INT)))
			->orderBy('configvalue', 'ASC')
			->addOrderBy('userid', 'ASC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		$result = $qb->execute();

		$uids = [];
		while ($row = $result->fetch()) {
			$uids[] = (string)$row['userid'];
		}

		$result->closeCursor();

		return $uids;
	}

	public function getDeletionDateTime(string $userId): ?\DateTimeImmutable {
		return $this->getPreferenceDateTime($userId, 'deletion');
	}

	/**
	 * Return all metadata needed to validate a deletion decision.
	 *
	 * @return array{
	 *     deletion: \DateTimeImmutable,
	 *     withdrawal: ?\DateTimeImmutable,
	 *     markedAt: ?\DateTimeImmutable,
	 *     lastAccountChange: ?\DateTimeImmutable
	 * }|null
	 */
	public function getDeletionState(string $userId): ?array {
		$deletion = $this->getPreferenceDateTime($userId, 'deletion');
		if ($deletion === null) {
			return null;
		}

		return [
			'deletion' => $deletion,
			'withdrawal' => $this->getPreferenceDateTime($userId, 'withdrawal'),
			'markedAt' => $this->getPreferenceDateTime($userId, 'deletion_marked_at'),
			'lastAccountChange' => $this->getPreferenceDateTime($userId, 'last_account_change'),
		];
	}

	/**
	 * Remove only deletion-related provisioning state.
	 *
	 * last_account_change is intentionally retained so delayed events cannot
	 * overwrite a newer state after deletion has been cancelled.
	 */
	public function deleteDeletionPreferencesById(string $userId): bool {
		$qb = $this->db->getQueryBuilder();

		$keyExpression = $qb->expr()->orX(
			$qb->expr()->eq('configkey', $qb->createNamedParameter(self::DELETION_KEYS[0])),
			$qb->expr()->eq('configkey', $qb->createNamedParameter(self::DELETION_KEYS[1])),
			$qb->expr()->eq('configkey', $qb->createNamedParameter(self::DELETION_KEYS[2]))
		);

		$qb->delete('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($keyExpression)
			->execute();

		return true;
	}

	/**
	 * Move a failed deletion attempt out of the current batch.
	 */
	public function postponeDeletion(string $userId, \DateTimeInterface $retryAt): bool {
		$qb = $this->db->getQueryBuilder();

		$qb->update('preferences')
			->set('configvalue', $qb->createNamedParameter($retryAt->getTimestamp(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('deletion')))
			->execute();

		return true;
	}

	public function countMigrated(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('nmcuser_oidc')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('migrated')));

		$result = $qb->execute();
		$column = (int)$result->fetchOne();
		$result->closeCursor();

		return $column;
	}

	/**
	 * Delete all user preferences after the user itself has disappeared.
	 */
	public function deleteUserPreferenceById(string $userId): bool {
		$qb = $this->db->getQueryBuilder();

		$qb->delete('preferences')
		->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
		->execute();

		return true; // Return true to indicate successful deletion
	}

	private function getPreferenceDateTime(string $userId, string $key): ?\DateTimeImmutable {
		$qb = $this->db->getQueryBuilder();

		$qb->select('configvalue')
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->setMaxResults(1);

		$result = $qb->execute();
		$value = $result->fetchOne();
		$result->closeCursor();

		if ($value === false || $value === null || $value === '' || !is_numeric($value)) {
			return null;
		}

		$timestamp = (int)$value;
		if ($timestamp <= 0) {
			return null;
		}

		return (new \DateTimeImmutable())->setTimestamp($timestamp);
	}
}
