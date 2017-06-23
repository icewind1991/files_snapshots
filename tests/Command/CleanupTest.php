<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, snapshot 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, snapshot 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OCA\Files_Snapshots\Tests\Command;


use OCA\Files_Snapshots\Command\CleanUp;
use Test\TestCase;
use OC\User\Manager;
use OCP\Files\IRootFolder;

/**
 * Class CleanupTest
 *
 * @group DB
 *
 * @package OCA\Files_Snapshots\Tests\Command
 */
class CleanupTest extends TestCase {

	/** @var  CleanUp */
	protected $cleanup;

	/** @var \PHPUnit_Framework_MockObject_MockObject | Manager */
	protected $userManager;

	/** @var \PHPUnit_Framework_MockObject_MockObject | IRootFolder */
	protected $rootFolder;

	public function setUp() {
		parent::setUp();

		$this->rootFolder = $this->getMockBuilder('OCP\Files\IRootFolder')
			->disableOriginalConstructor()->getMock();
		$this->userManager = $this->getMockBuilder('OC\User\Manager')
			->disableOriginalConstructor()->getMock();


		$this->cleanup = new CleanUp($this->rootFolder, $this->userManager);
	}

	/**
	 * @dataProvider dataTestDeleteSnapshots
	 * @param boolean $nodeExists
	 */
	public function testDeleteSnapshots($nodeExists) {

		$this->rootFolder->expects($this->once())
			->method('nodeExists')
			->with('/testUser/files_snapshots')
			->willReturn($nodeExists);


		if($nodeExists) {
			$this->rootFolder->expects($this->once())
				->method('get')
				->with('/testUser/files_snapshots')
				->willReturn($this->rootFolder);
			$this->rootFolder->expects($this->once())
				->method('delete');
		} else {
			$this->rootFolder->expects($this->never())
				->method('get');
			$this->rootFolder->expects($this->never())
				->method('delete');
		}

		$this->invokePrivate($this->cleanup, 'deleteSnapshots', ['testUser']);
	}

	public function dataTestDeleteSnapshots() {
		return array(
			array(true),
			array(false)
		);
	}


	/**
	 * test delete snapshots from users given as parameter
	 */
	public function testExecuteDeleteListOfUsers() {
		$userIds = ['user1', 'user2', 'user3'];

		$instance = $this->getMockBuilder('OCA\Files_Snapshots\Command\CleanUp')
			->setMethods(['deleteSnapshots'])
			->setConstructorArgs([$this->rootFolder, $this->userManager])
			->getMock();
		$instance->expects($this->exactly(count($userIds)))
			->method('deleteSnapshots')
			->willReturnCallback(function ($user) use ($userIds) {
				$this->assertTrue(in_array($user, $userIds));
			});

		$this->userManager->expects($this->exactly(count($userIds)))
			->method('userExists')->willReturn(true);

		$inputInterface = $this->getMockBuilder('\Symfony\Component\Console\Input\InputInterface')
			->disableOriginalConstructor()->getMock();
		$inputInterface->expects($this->once())->method('getArgument')
			->with('user_id')
			->willReturn($userIds);

		$outputInterface = $this->getMockBuilder('\Symfony\Component\Console\Output\OutputInterface')
			->disableOriginalConstructor()->getMock();

		$this->invokePrivate($instance, 'execute', [$inputInterface, $outputInterface]);
	}

	/**
	 * test delete snapshots of all users
	 */
	public function testExecuteAllUsers() {
		$userIds = [];
		$backendUsers = ['user1', 'user2'];

		$instance = $this->getMockBuilder('OCA\Files_Snapshots\Command\CleanUp')
			->setMethods(['deleteSnapshots'])
			->setConstructorArgs([$this->rootFolder, $this->userManager])
			->getMock();

		$backend = $this->getMockBuilder('OC_User_Interface')
			->disableOriginalConstructor()->getMock();
		$backend->expects($this->once())->method('getUsers')
			->with('', 500, 0)
			->willReturn($backendUsers);

		$instance->expects($this->exactly(count($backendUsers)))
			->method('deleteSnapshots')
			->willReturnCallback(function ($user) use ($backendUsers) {
				$this->assertTrue(in_array($user, $backendUsers));
			});

		$inputInterface = $this->getMockBuilder('\Symfony\Component\Console\Input\InputInterface')
			->disableOriginalConstructor()->getMock();
		$inputInterface->expects($this->once())->method('getArgument')
			->with('user_id')
			->willReturn($userIds);

		$outputInterface = $this->getMockBuilder('\Symfony\Component\Console\Output\OutputInterface')
			->disableOriginalConstructor()->getMock();

		$this->userManager->expects($this->once())
				->method('getBackends')
				->willReturn([$backend]);

		$this->invokePrivate($instance, 'execute', [$inputInterface, $outputInterface]);
	}

}
