<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Unit\Command;

use App\Command\RedisSyncCommand;
use App\Service\DKIM\Config\Manager;
use App\Service\FetchmailAccount\AccountWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class RedisSyncCommandTest extends TestCase
{
    private CommandTester $commandTester;

    private MockObject $managerMock;
    private MockObject $accountWriterMock;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(Manager::class);
        $this->accountWriterMock = $this->createMock(AccountWriter::class);

        $application = new Application();
        $application->add(new RedisSyncCommand($this->managerMock, $this->accountWriterMock));

        $this->commandTester = new CommandTester($application->find('redis:sync'));
    }

    public function testExecute(): void
    {
        $this->managerMock->expects($this->once())->method('refresh');
        $this->accountWriterMock->expects($this->once())->method('write');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}
