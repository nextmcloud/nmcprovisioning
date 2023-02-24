<?php

namespace OCA\NextMagentaCloudProvisioning\Service;

use OC\User\Manager;
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

    private Manager $userManager;

    /**
     * @param \OCP\IDBConnection $db
     * @param \OCP\IGroupManager $groupManager
     */
    public function __construct(IDBConnection $db, IGroupManager $groupManager, Manager $userManager)
    {
        $this->groupHelper = new GroupHelper();
        $this->groupMapping = $this->groupHelper->getGroupMapping();
        $this->db = $db;
        $this->groupManager = $groupManager;
        $this->userManager = $userManager;
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

    public function migrateGroups(int $limit = 1000, int $offset = 0, bool $fullAuto = false): void
    {
        $query = $this->db->getQueryBuilder();
        $query->select($query->func()->count('userid'))
            ->from('preferences')
            ->where($query->expr()->eq('appid', $query->createNamedParameter('files')));
        $users = $query->execute()->fetchAll()[0]["COUNT(`userid`)"];
        var_dump("Migrating $users users");

        for ($i = $offset; $i < $users; $i += $limit) {
            var_dump("Migrating users " . $i . " to " . ($i + $limit));
            try {
                foreach ($this->getGroups() as $group) {
                    $this->migrateGroup($group['gid'], $i + $limit, $i);
                }
            } catch (Exception $e) {
            }
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
        if (is_null($this->groupMapping[$newGroupName])) {
            var_dump("Group $newGroupName not found in mapping");
            return;
        }
        var_dump("Migrate group $newGroupName");
        $users = $this->searchUserByRangeQuota(intval($this->groupMapping[$newGroupName]['old_quota']), $this->groupMapping[$newGroupName]['search_range'], $limit, $offset);

        if ($this->groupMapping[$newGroupName]['old_quota_alias'] != null) {
            $users = array_merge($users, $this->searchUserByRangeQuota($this->groupMapping[$newGroupName]['old_quota_alias'], 0.2, $limit, $offset));
        }

        foreach ($users as $user) {
            //IUser
            $iUser = $this->userManager->get($user['userid']);
            $newGroup->addUser($iUser);
        }
    }

    public function searchUserByRangeQuota(int|string $quota, int|float $range = 1, int $limit = 1000, int $offset = 0): array
    {
        $query = $this->db->getQueryBuilder();
        $query->select('userid')
            ->from('preferences')
            ->where($query->expr()->eq('appid', $query->createNamedParameter('files')))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter('quota')));

        if (is_int($quota)) {
            $query->andWhere("CAST(configvalue AS SIGNED) BETWEEN $quota - $range AND $quota + $range");
        } else {
            $split = explode(" ", $quota);
            $range = range(intval($split[0]) - $range, intval($split[0]) + $range, 0.1);
            $query->andWhere("configvalue IN ('" . implode(" " . $split[1] . "','", $range) . " " . $split[1] . "')");
        }

        //Set limit and offset
        $query->setMaxResults($limit);
        $query->setFirstResult($offset);

        var_dump($query->getSQL());
        //->andWhere("CAST(configvalue AS SIGNED) BETWEEN $quota - $range AND $quota + $range");
        $result = $query->execute();
        $users = $result->fetchAll();
        $result->closeCursor();
        return $users;
    }

}