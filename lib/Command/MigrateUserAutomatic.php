<?php

namespace OCA\NextMagentaCloudProvisioning\Command;

use OCA\NextMagentaCloudProvisioning\Service\GroupMigration;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MigrateUserAutomatic
 * @package OCA\NextMagentaCloudProvisioning\Command
 */
class MigrateUserAutomatic extends Command {

	private IDBConnection $db;
	private GroupMigration $groupMigration;

	public function __construct(IDBConnection $db, IGroupManager $groupManager, IUserManager $userManager, IConfig $config) {
		parent::__construct();
		$this->db = $db;
		$this->groupMigration = new GroupMigration($db, $groupManager, $userManager, $config);
	}

	/**
	 * It sets the name and the description of the command
	 * @return void
	 */
	protected function configure(): void {
		$this
			->setName('app:migrate_provisioning_groups')
			->setDescription('Migration of provisioning groups')
			->addArgument('limit', null, 'Limit of groups to migrate')
			->addArgument('offset', null, 'Offset of groups to migrate')
			->addArgument('auto', null, 'Auto migration of groups');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->groupMigration->migrateGroups($input->getArgument('limit') ?: 1000, $input->getArgument('offset') ?: 0, $input->getArgument('auto') ?: false);
	}
}
