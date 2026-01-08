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
    private MockObject&ConnectionCheckService $connectionCheckService;

    protected function setUp(): void
    {
        $this->connectionCheckService = $this->createMock(ConnectionCheckService::class);

        $application = new Application();
        $application->addCommand(new SystemCheckCommand($this->connectionCheckService, '5s'));

        $this->commandTester = new CommandTester($application->find('system:check'));
    }

    public function testBasicCheckSuccess(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(false)
            ->willReturn([
                'mysql' => null,
                'redis' => null,
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
        $this->assertStringNotContainsString('Doveadm', $output);
        $this->assertStringNotContainsString('Rspamd', $output);
    }

    public function testBasicCheckWithMySQLError(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(false)
            ->willReturn([
                'mysql' => 'Connection refused',
                'redis' => null,
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Your MySQL connection failed', $output);
        $this->assertStringContainsString('Connection refused', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
    }

    public function testBasicCheckWithRedisError(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(false)
            ->willReturn([
                'mysql' => null,
                'redis' => 'Authentication failed',
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[ERROR] Your Redis connection failed', $output);
        $this->assertStringContainsString('Authentication failed', $output);
    }

    public function testBasicCheckWithBothErrors(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(false)
            ->willReturn([
                'mysql' => 'Database not found',
                'redis' => 'Connection refused',
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Your MySQL connection failed', $output);
        $this->assertStringContainsString('Database not found', $output);
        $this->assertStringContainsString('[ERROR] Your Redis connection failed', $output);
        $this->assertStringContainsString('Connection refused', $output);
    }

    public function testCheckAllSuccess(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(true)
            ->willReturn([
                'mysql' => null,
                'redis' => null,
                'doveadm' => null,
                'rspamd' => null,
            ]);

        $this->commandTester->execute(['--all' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
        $this->assertStringContainsString('[OK] Doveadm connection is working.', $output);
        $this->assertStringContainsString('[OK] Rspamd connection is working.', $output);
    }

    public function testCheckAllWithDoveadmError(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(true)
            ->willReturn([
                'mysql' => null,
                'redis' => null,
                'doveadm' => 'Connection failed',
                'rspamd' => null,
            ]);

        $this->commandTester->execute(['--all' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
        $this->assertStringContainsString('[ERROR] Your Doveadm connection failed', $output);
        $this->assertStringContainsString('Connection failed', $output);
        $this->assertStringContainsString('[OK] Rspamd connection is working.', $output);
    }

    public function testCheckAllWithRspamdError(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(true)
            ->willReturn([
                'mysql' => null,
                'redis' => null,
                'doveadm' => null,
                'rspamd' => 'Connection timeout',
            ]);

        $this->commandTester->execute(['--all' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
        $this->assertStringContainsString('[OK] Doveadm connection is working.', $output);
        $this->assertStringContainsString('[ERROR] Your Rspamd connection failed', $output);
        $this->assertStringContainsString('Connection timeout', $output);
    }

    public function testCheckAllWithAllErrors(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(true)
            ->willReturn([
                'mysql' => 'Database not found',
                'redis' => 'Connection refused',
                'doveadm' => 'Authentication failed',
                'rspamd' => 'Connection timeout',
            ]);

        $this->commandTester->execute(['--all' => true]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('[ERROR] Your MySQL connection failed', $output);
        $this->assertStringContainsString('[ERROR] Your Redis connection failed', $output);
        $this->assertStringContainsString('[ERROR] Your Doveadm connection failed', $output);
        $this->assertStringContainsString('[ERROR] Your Rspamd connection failed', $output);
    }

    public function testWaitOptionSuccess(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(false)
            ->willReturn([
                'mysql' => null,
                'redis' => null,
            ]);

        $this->commandTester->execute(['--wait' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Waiting for dependencies to become available', $output);
        $this->assertStringContainsString('[OK] All dependencies are now available.', $output);
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
    }

    public function testWaitOptionWithInitialFailureThenSuccess(): void
    {
        $callCount = 0;
        $this->connectionCheckService
            ->expects($this->atLeast(2))
            ->method('checkAll')
            ->with(false)
            ->willReturnCallback(function () use (&$callCount) {
                ++$callCount;
                if (1 === $callCount) {
                    return [
                        'mysql' => 'Connection refused',
                        'redis' => null,
                    ];
                }

                return [
                    'mysql' => null,
                    'redis' => null,
                ];
            });

        $this->commandTester->execute(['--wait' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Waiting for dependencies to become available', $output);
        $this->assertStringContainsString('[OK] All dependencies are now available.', $output);
    }

    public function testWaitOptionWithAllFlagSuccess(): void
    {
        $this->connectionCheckService
            ->expects($this->once())
            ->method('checkAll')
            ->with(true)
            ->willReturn([
                'mysql' => null,
                'redis' => null,
                'doveadm' => null,
                'rspamd' => null,
            ]);

        $this->commandTester->execute(['--wait' => true, '--all' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Waiting for dependencies to become available', $output);
        $this->assertStringContainsString('[OK] All dependencies are now available.', $output);
        $this->assertStringContainsString('[OK] MySQL connection is working.', $output);
        $this->assertStringContainsString('[OK] Redis connection is working.', $output);
        $this->assertStringContainsString('[OK] Doveadm connection is working.', $output);
        $this->assertStringContainsString('[OK] Rspamd connection is working.', $output);
    }

    public function testWaitOptionWithAllFlagAndDoveadmError(): void
    {
        $callCount = 0;
        $this->connectionCheckService
            ->expects($this->atLeast(2))
            ->method('checkAll')
            ->with(true)
            ->willReturnCallback(function () use (&$callCount) {
                ++$callCount;
                if (1 === $callCount) {
                    return [
                        'mysql' => null,
                        'redis' => null,
                        'doveadm' => 'Connection failed',
                        'rspamd' => null,
                    ];
                }

                return [
                    'mysql' => null,
                    'redis' => null,
                    'doveadm' => null,
                    'rspamd' => null,
                ];
            });

        $this->commandTester->execute(['--wait' => true, '--all' => true]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Waiting for dependencies to become available', $output);
        $this->assertStringContainsString('[OK] All dependencies are now available.', $output);
    }
}
