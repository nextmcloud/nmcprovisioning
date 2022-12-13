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

use OCP\IDBConnection;

use OCP\DB\QueryBuilder\IQueryBuilder;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;

class UserQueries {
	/** @var IDBConnection */
	private $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	/**
	 * Find all users marked for deletion with a deletion date
	 * before $refDate
	 */
	public function findDeletions(\DateTime $refDate, $limit = null, $offset = null): array {
		$refTs = $refDate->getTimestamp();
		
		$qb = $this->db->getQueryBuilder();
		//->andWhere($qb->expr()->lt('configvalue', $qb->createNamedParameter($refTs, IQueryBuilder::PARAM_INT), IQueryBuilder::PARAM_INT))
		$qb->select('userid')
			->from('preferences')
            ->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
            ->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('deletion')))
            ->andWhere($qb->expr()->lt('configvalue', $qb->createNamedParameter($refTs, IQueryBuilder::PARAM_INT)))
            ->setMaxResults($limit)
			->setFirstResult($offset);

		$result = $qb->execute();
        $uids = [];
		while ($row = $result->fetch()) {
			\array_push($uids, (string)$row['userid']);
		}
		return $uids;
	}

	public function countMigrated(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from("preferences")
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('nmcuser_oidc')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('migrated')));
		$result = $query->execute();
		$column = (int)$result->fetchOne();
		$result->closeCursor();
		return $column;
	}
}
