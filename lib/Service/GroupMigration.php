<?php

namespace OCA\NextMagentaCloudProvisioning\Service;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\IGroupManager;

class GroupMigration
{

    /**
     * @var array $groupMapping
     */
    private array $groupMapping;

    /**
     * @var \OCP\IDBConnection
     */
    private IDBConnection $db;

    /**
     * @var \OCP\IGroupManager
     */
    private IGroupManager $groupManager;

    private RichDocuments $richDocuments;

    private GroupHelper $groupHelper;

    /**
     * @param \OCP\IDBConnection $db
     * @param \OCP\IGroupManager $groupManager
     */
    public function __construct(IDBConnection $db, IGroupManager $groupManager)
    {
        $this->groupHelper = new GroupHelper();
        $this->groupMapping = $this->groupHelper->getGroupMapping();
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->richDocuments = new RichDocuments($db);
    }

    /**
     * @return array
     * @throws \OCP\DB\Exception
     */
    public function getGroups(): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('gid')
            ->from('groups');
        $result = $query->execute();
        $groups = $result->fetchAll();
        $result->closeCursor();
        return $groups;
    }

    public function migrateGroups(int $limit = 1000, int $offset = 0): void
    {
        try {
            foreach ($this->getGroups() as $group) {
                $this->migrateGroup($group['gid'], $limit, $offset);
            }
        } catch (Exception $e) {
        }
    }

    public function createNewGroups(): void
    {
        foreach ($this->groupMapping as $group) {
            if (!$this->groupManager->get($group['name'])) {
                $this->createNewGroup($group['name']);
            }
        }
    }

    public function createNewGroup(string $name): void
    {
        $this->groupManager->createGroup($name);
        $this->richDocuments->addUseGroupToCollabora($name);
        $groupSpecs = $this->groupHelper->getGroupMapping()[strtoupper($name)];
        if (!key_exists('ready_only', $groupSpecs) || !$groupSpecs['ready_only']) {
            $this->richDocuments->addEditGroupToCollabora($name);
        }
    }

    public function migrateGroup(string $newGroupName, int $limit = 1000, int $offset = 0): void
    {
        $newGroup = $this->groupManager->get($newGroupName);
        if (!$newGroup) {
            $this->createNewGroup($newGroupName);
        }
        $users = $this->searchUserByRangeQuota($this->groupMapping[$newGroupName]['old_quota'], $this->groupMapping[$newGroupName]['search_range'], $limit, $offset);
        foreach ($users as $user) {
            $newGroup->addUser($user);
        }
    }

    public function searchUserByRangeQuota(string $quota, int $range = 1, int $limit = 1000, int $offset = 0): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('userid')
            ->from('preferences')
            ->where($query->expr()->eq('appid', $query->createNamedParameter('files')))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter('quota')))
            ->andWhere("CAST(configvalue AS SIGNED) BETWEEN $quota - $range AND $quota + $range limit $limit offset $offset");
        //->andWhere("CAST(configvalue AS SIGNED) BETWEEN $quota - $range AND $quota + $range");
        $result = $query->execute();
        $users = $result->fetchAll();
        $result->closeCursor();
        return $users;
    }

}