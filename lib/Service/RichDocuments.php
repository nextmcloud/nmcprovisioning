<?php

namespace OCA\NextMagentaCloudProvisioning\Service;


use OCP\IDBConnection;

class RichDocuments
{

    /**
     * @var \OCP\IDBConnection
     */
    private IDBConnection $db;


    public function __construct(IDBConnection $db)
    {
        $this->db = $db;
    }

    /**
     * @param string $groupName
     * @return void
     */
    public function addUseGroupToCollabora(string $groupName): void
    {
        try {
            $this->updateUseGroupsInCollabora($this->addGroupToRichDocumentsArray($this->getUseGroupsFromCollabora(), $groupName));
        } catch (\OCP\DB\Exception $e) {
        }
    }

    /**
     * @param string $groupName
     * @return void
     */
    public function addEditGroupToCollabora(string $groupName): void
    {
        try {
            $this->updateEditGroupsInCollabora($this->addGroupToRichDocumentsArray($this->getEditGroupsFromCollabora(), $groupName));
        } catch (\OCP\DB\Exception $e) {
        }
    }

    public function removeUseGroupFromCollabora(string $groupName): void
    {
        try {
            $this->updateUseGroupsInCollabora($this->removeGroupFromRichDocumentsArray($this->getUseGroupsFromCollabora(), $groupName));
        } catch (\OCP\DB\Exception $e) {
        }
    }

    public function removeEditGroupFromCollabora(string $groupName): void
    {
        try {
            $this->updateEditGroupsInCollabora($this->removeGroupFromRichDocumentsArray($this->getEditGroupsFromCollabora(), $groupName));
        } catch (\OCP\DB\Exception $e) {
        }
    }

    /**
     * @return mixed
     * @throws \OCP\DB\Exception
     */
    public function getUseGroupsFromCollabora(): mixed
    {
        return $this->getGroupsFromCollabora('use_groups');
    }

    /**
     * @return mixed
     * @throws \OCP\DB\Exception
     */
    public function getEditGroupsFromCollabora(): mixed
    {
        return $this->getGroupsFromCollabora('edit_groups');
    }

    /**
     * @param string $type
     * @return mixed
     * @throws \OCP\DB\Exception
     */
    public function getGroupsFromCollabora(string $type): mixed
    {
        $groups = $this->db->executeQuery("SELECT configvalue FROM oc_appconfig WHERE configkey = ? AND appid = 'richdocuments'", [$type])->fetchAll()[0];
        return $groups["configvalue"];
    }

    /**
     * @param string $groups
     * @return void
     * @throws \OCP\DB\Exception
     */
    public function updateUseGroupsInCollabora(string $groups): void
    {
        $this->updateGroupsInCollabora('use_groups', $groups);
    }

    /**
     * @param string $groups
     * @return void
     * @throws \OCP\DB\Exception
     */
    public function updateEditGroupsInCollabora(string $groups): void
    {
        $this->updateGroupsInCollabora('edit_groups', $groups);
    }

    /**
     * @param string $type
     * @param string $groups
     * @return void
     * @throws \OCP\DB\Exception
     */
    public function updateGroupsInCollabora(string $type, string $groups): void
    {
        $this->db->executeQuery("UPDATE oc_appconfig SET configvalue = ? WHERE configkey = ? AND appid = 'richdocuments'", [$groups, $type]);
    }

    /**
     * @param ?string $old
     * @param string $add
     * @return string
     */
    public function addGroupToRichDocumentsArray(?string $old = "", string $add = ""): string
    {
        //Spam protection
        if(str_contains($old, '|'.$add) || str_contains($old, $add.'|') || $old === $add) {
            return $old;
        }

        if ($old == "") {
            return $add;
        }
        return $old . '|' . $add;
    }

    /**
     * @param string $old
     * @param string $remove
     * @return string
     */
    public function removeGroupFromRichDocumentsArray(string $old, string $remove): string
    {
        $groups = explode('|', $old);
        $new = '';
        foreach ($groups as $group) {
            if ($group != $remove) {
                $new .= $group . '|';
            }
        }
        return rtrim($new, '|');
    }

}