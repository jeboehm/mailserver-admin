<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\SystemCheckCommand;
use App\Service\ConnectionCheckService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SystemCheckCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private MockObject|ConnectionCheckService $connectionCheckService;

    protected function setUp(): void
    {
        $this->connectionCheckService = $this->createMock(ConnectionCheckService::class);

        $application = new Application();
        $application->add(new SystemCheckCommand($this->connectionCheckService));

        $this->commandTester = new CommandTester($application->find('system:check'));
    }

    public function testExecuteBothConnectionsOk(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn([
                'mysql' => null,
                'redis' => null,
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
    }

    public function testExecuteMySQLFailure(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn([
                'mysql' => 'Connection refused',
                'redis' => null,
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Your MySQL connection failed because of:', $output);
        $this->assertStringContainsString('Connection refused', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
    }

    public function testExecuteRedisFailure(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn([
                'mysql' => null,
                'redis' => 'Connection refused',
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[ERROR] Your Redis connection failed because of:', $output);
        $this->assertStringContainsString('Connection refused', $output);
    }

    public function testExecuteBothFailures(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->willReturn([
                'mysql' => 'Database not found',
                'redis' => 'Connection refused',
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Your MySQL connection failed because of:', $output);
        $this->assertStringContainsString('Database not found', $output);
        $this->assertStringContainsString('[ERROR] Your Redis connection failed because of:', $output);
        $this->assertStringContainsString('Connection refused', $output);
    }
}
