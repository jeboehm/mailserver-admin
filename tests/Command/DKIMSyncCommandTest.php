<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Command;

use App\Command\DKIMSyncCommand;
use App\Service\DKIM\Config\Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class DKIMSyncCommandTest extends TestCase
{
    /** @var CommandTester */
    private $commandTester;

    /** @var Manager|MockObject */
    private $managerMock;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(Manager::class);

        $application = new Application();
        $application->add(new DKIMSyncCommand(null, $this->managerMock));

        $this->commandTester = new CommandTester($application->find('dkim:refresh'));
    }

    public function testExecute(): void
    {
        $this->managerMock->expects($this->once())->method('refresh');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}
