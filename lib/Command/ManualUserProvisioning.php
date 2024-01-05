<?php

namespace OCA\NextMagentaCloudProvisioning\Command;

use OCA\NextMagentaCloudProvisioning\User\NmcUserService;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class MigrateUserAutomatic
 * @package OCA\NextMagentaCloudProvisioning\Command
 */
class ManualUserProvisioning extends Command {

	private NmcUserService $nmcUserService;
	private IUserManager $userManager;

	public function __construct(IUserManager $userManager, NmcUserService $nmcUserService) {
		parent::__construct();
		$this->userManager = $userManager;
		$this->nmcUserService = $nmcUserService;
	}

	/**
	 * It sets the name and the description of the command
	 * @return void
	 */
	protected function configure(): void {
		$this
			->setName('nmcprovisioning:manual_user_provisioning')
			->setDescription('Manual provisioning of users for profiling')
			->addArgument('userId', InputArgument::REQUIRED, 'Limit of groups to migrate')
			->addOption('displayname', null, InputOption::VALUE_REQUIRED, 'Offset of groups to migrate')
			->addOption('email', null, InputOption::VALUE_REQUIRED, 'Auto migration of groups')
			->addOption('quota', null, InputOption::VALUE_REQUIRED, 'Auto migration of groups')
			->addOption('enabled', null, InputOption::VALUE_REQUIRED, 'Auto migration of groups')
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$user = $this->userManager->get($input->getArgument('userId'));
		$displayname = $input->getOption('displayname');
		$email = $input->getOption('email');
		$quota = $input->getOption('quota');
		$enabled = $input->hasOption('enabled') ? (bool)$input->getOption('enabled') : null;

		$this->nmcUserService->update(
			$user,
			$displayname,
			$email,
			$quota,
			$enabled
		);
	}
}
