<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Command;

use App\Command\VersionCheckCommand;
use App\Service\ApplicationVersionService;
use App\Service\GitHubTagService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class VersionCheckCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private MockObject|ApplicationVersionService $applicationVersionService;
    private MockObject|GitHubTagService $gitHubTagService;

    protected function setUp(): void
    {
        $this->applicationVersionService = $this->createMock(ApplicationVersionService::class);
        $this->gitHubTagService = $this->createMock(GitHubTagService::class);

        $application = new Application();
        $application->addCommand(new VersionCheckCommand(
            $this->applicationVersionService,
            $this->gitHubTagService
        ));

        $this->commandTester = new CommandTester($application->find('version:check'));
    }

    public function testExecuteAllUpToDate(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.3');
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('2.0.0');

        $this->gitHubTagService
            ->expects($this->exactly(2))
            ->method('getLatestTag')
            ->willReturnMap([
                ['jeboehm', 'mailserver-admin', '1.2.3'],
                ['jeboehm', 'docker-mailserver', '2.0.0'],
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Checking versions...', $output);
        $this->assertStringContainsString('Up to date', $output);
    }

    public function testExecuteAdminOutdated(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.2');
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('2.0.0');

        $this->gitHubTagService
            ->expects($this->exactly(2))
            ->method('getLatestTag')
            ->willReturnMap([
                ['jeboehm', 'mailserver-admin', '1.2.3'],
                ['jeboehm', 'docker-mailserver', '2.0.0'],
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Outdated', $output);
    }

    public function testExecuteMailserverOutdated(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.3');
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('1.9.9');

        $this->gitHubTagService
            ->expects($this->exactly(2))
            ->method('getLatestTag')
            ->willReturnMap([
                ['jeboehm', 'mailserver-admin', '1.2.3'],
                ['jeboehm', 'docker-mailserver', '2.0.0'],
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(1, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Outdated', $output);
    }

    public function testExecuteAdminVersionNotFound(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn(null);
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('2.0.0');

        $this->gitHubTagService
            ->expects($this->exactly(2))
            ->method('getLatestTag')
            ->willReturnMap([
                ['jeboehm', 'mailserver-admin', '1.2.3'],
                ['jeboehm', 'docker-mailserver', '2.0.0'],
            ]);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Not found', $output);
        $this->assertStringContainsString('Unknown', $output);
    }

    public function testExecuteLatestVersionsNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.3');
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('2.0.0');

        $this->gitHubTagService
            ->expects($this->exactly(2))
            ->method('getLatestTag')
            ->willReturn(null);

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Error', $output);
    }
}
