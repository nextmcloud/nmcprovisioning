<?php

declare(strict_types=1);

namespace OCA\NextMagentaCloudProvisioning\UnitTest;

use OCA\NextMagentaCloudProvisioning\AppInfo\Application;
use OCA\NextMagentaCloudProvisioning\Db\UserQueries;
use OCA\NextMagentaCloudProvisioning\User\UserAccountDeletionJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserAccountDeletionJobTest extends TestCase {
	public function setUp(): void {
		parent::setUp();
		$this->app = new \OCP\AppFramework\App(Application::APP_ID);
		$this->userQueries = $this->createMock(UserQueries::class);
		$this->userManager = $this->getMockForAbstractClass(IUserManager::class);
		$this->job = new UserAccountDeletionJob($this->app->getContainer()->get(ITimeFactory::class),
			$this->app->getContainer()->get(LoggerInterface::class),
			$this->userQueries,
			$this->userManager);
	}

	public function testJobRunNone() {
		$this->userQueries->expects($this->once())
			->method("findDeletions")
			->willReturn([]);  // Keine Benutzer zum Löschen
	
		// Mock für den Benutzer, der nie gelöscht wird
		$user = $this->getMockForAbstractClass(IUser::class);
		$user->expects($this->never())->method("delete");  // delete wird niemals aufgerufen
		$this->userManager->expects($this->never())->method("get");  // get wird auch nie aufgerufen
	
		$this->job->run(null);
	}

	public function testJobRunMultiple() {
		// Simuliere mehrere Aufrufe von findDeletions, um Benutzer in verschiedenen Batches zu finden
		$this->userQueries->expects($this->exactly(3))  // findDeletions wird dreimal aufgerufen
			->method("findDeletions")
			->willReturnOnConsecutiveCalls(
				['120042010000000004200001', '120042010000000004200002'],  // Erstes Batch
				['120042010000000004200003'],  // Zweites Batch
				[]  // Drittens Batch, keine Benutzer mehr zu löschen
			);
	
		// Mock des Benutzers, der gelöscht werden soll
		$user = $this->getMockForAbstractClass(IUser::class);
		$user->expects($this->exactly(3))->method("delete");  // delete wird für jeden Benutzer genau einmal aufgerufen
		$this->userManager->expects($this->exactly(3))  // get wird für jeden Benutzer aufgerufen
			->method("get")
			->withConsecutive(
				[$this->equalTo('120042010000000004200001')],
				[$this->equalTo('120042010000000004200002')],
				[$this->equalTo('120042010000000004200003')]
			)
			->willReturn($user);
	
		$this->job->run(null);
	}

	public function testJobRunOne() {
		// Simuliere findDeletions mit nur einem Benutzer
		$this->userQueries->expects($this->exactly(2))
			->method("findDeletions")
			->willReturnOnConsecutiveCalls(
				['120042010000000004200001'],  // Erstes Batch
				[]  // Zweites Batch, keine Benutzer mehr zu löschen
			);
	
		// Mock des Benutzers, der gelöscht werden soll
		$user = $this->getMockForAbstractClass(IUser::class);
		$user->expects($this->once())->method("delete");  // delete wird genau einmal aufgerufen
		$this->userManager->expects($this->once())  // get wird genau einmal aufgerufen
			->method("get")
			->with($this->equalTo('120042010000000004200001'))
			->willReturn($user);
	
		$this->job->run(null);
	}
}
