<?php

namespace OCA\NextMagentaCloudProvisioning\Command;

use OCA\NextMagentaCloudProvisioning\Service\GroupMigration;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class CreateNewGroups
 * @package OCA\NextMagentaCloudProvisioning\Command
 */
class CreateNewGroups extends Command
{

    private IDBConnection $db;
    private GroupMigration $groupMigration;

    public function __construct(IDBConnection $db, IGroupManager $groupManager)
    {
        parent::__construct();
        $this->db = $db;
        $this->groupMigration = new GroupMigration($db, $groupManager);
    }

    /**
     * It sets the name and the description of the command
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setName('app:generate_new_provisioning_groups')
            ->setDescription('Generate new provisioning groups');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->groupMigration->createNewGroups();
    }


}