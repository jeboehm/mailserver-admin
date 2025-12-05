<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\RedisSyncCommand;
use App\Service\ConnectionCheckService;
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
    private MockObject $connectionCheckServiceMock;

    protected function setUp(): void
    {
        $this->managerMock = $this->createMock(Manager::class);
        $this->accountWriterMock = $this->createMock(AccountWriter::class);
        $this->connectionCheckServiceMock = $this->createMock(ConnectionCheckService::class);

        $application = new Application();
        $application->add(new RedisSyncCommand($this->managerMock, $this->accountWriterMock, $this->connectionCheckServiceMock));

        $this->commandTester = new CommandTester($application->find('redis:sync'));
    }

    public function testExecute(): void
    {
        $this->connectionCheckServiceMock
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn(['mysql' => null, 'redis' => null]);

        $this->managerMock->expects($this->once())->method('refresh');
        $this->accountWriterMock->expects($this->once())->method('write');

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }
}
