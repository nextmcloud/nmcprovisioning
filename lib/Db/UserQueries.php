<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Bernd Rederlechner <Bernd.Rederlechner@t-systems.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\NextMagentaCloudProvisioning\Db;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;

use OCP\DB\QueryBuilder\IQueryBuilder;

use OCP\IDBConnection;

class UserQueries {
	/** @var IDBConnection */
	private $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * Find all users marked for deletion with a deletion date
	 * before $refDate.
	 */
	public function findDeletions(\DateTime $refDate, ?int $limit = null): array {
		$refTs = $refDate->getTimestamp();

		$qb = $this->db->getQueryBuilder();
		$qb->select('userid')
			->from('preferences')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('deletion')))
			->andWhere($qb->expr()->lt('configvalue', $qb->createNamedParameter($refTs, IQueryBuilder::PARAM_INT)))
			->orderBy('configvalue', 'ASC');

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

	public function getDeletionDateTime(string $userId): ?\DateTime {
		$qb = $this->db->getQueryBuilder();

		$qb->select('configvalue')
			->from('preferences')
			->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('deletion')))
			->setMaxResults(1);

		$result = $qb->execute();
		$value = $result->fetchOne();
		$result->closeCursor();

		if ($value === false || $value === null || $value === '') {
			return null;
		}

		$deletionDate = new \DateTime();
		$deletionDate->setTimestamp((int)$value);

		return $deletionDate;
	}

	public function countMigrated(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from("preferences")
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('nmcuser_oidc')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('migrated')));
		$result = $qb->execute();
		$column = (int)$result->fetchOne();
		$result->closeCursor();
		return $column;
	}

	/**
	 * Delete a user preference by their user ID
	 *
	 * @param string $userId
	 * @return bool True if deletion was successful, false otherwise
	 */
	public function deleteUserPreferenceById(string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		
		$qb->delete('preferences')
		->where($qb->expr()->eq('userid', $qb->createNamedParameter($userId)))
		->execute();

		return true; // Return true to indicate successful deletion
	}
}
