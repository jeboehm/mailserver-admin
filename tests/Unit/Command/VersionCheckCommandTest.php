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
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
        $this->assertStringContainsString('mailserver-admin', $output);
        $this->assertStringContainsString('docker-mailserver', $output);
        $this->assertStringContainsString('1.2.3', $output);
        $this->assertStringContainsString('2.0.0', $output);
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

    public function testExecuteBothOutdated(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.2');
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

    public function testExecuteMailserverVersionNotFound(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.3');
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn(null);

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

    public function testExecuteAdminGitHubError(): void
    {
        $exception = new class('Network error') extends \RuntimeException implements TransportExceptionInterface {
        };

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
            ->willReturnCallback(function ($owner, $repo) use ($exception) {
                if ('mailserver-admin' === $repo) {
                    throw $exception;
                }

                return '2.0.0';
            });

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Error', $output);
        $this->assertStringContainsString('Failed to fetch latest admin version: Network error', $output);
    }

    public function testExecuteMailserverGitHubError(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $exception = new class($response, 'Server error') extends \RuntimeException implements ServerExceptionInterface, HttpExceptionInterface {
            public function __construct(private ResponseInterface $response, string $message = '')
            {
                parent::__construct($message);
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

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
            ->willReturnCallback(function ($owner, $repo) use ($exception) {
                if ('docker-mailserver' === $repo) {
                    throw $exception;
                }

                return '1.2.3';
            });

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Error', $output);
        $this->assertStringContainsString('Failed to fetch latest mailserver version: Server error', $output);
    }

    public function testExecuteBothGitHubErrors(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $adminException = new class($response, 'Client error') extends \RuntimeException implements ClientExceptionInterface, HttpExceptionInterface {
            public function __construct(private ResponseInterface $response, string $message = '')
            {
                parent::__construct($message);
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

        $mailserverResponse = $this->createMock(ResponseInterface::class);
        $mailserverException = new class($mailserverResponse, 'Redirection error') extends \RuntimeException implements RedirectionExceptionInterface, HttpExceptionInterface {
            public function __construct(private ResponseInterface $response, string $message = '')
            {
                parent::__construct($message);
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };

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
            ->willReturnCallback(function ($owner, $repo) use ($adminException, $mailserverException) {
                if ('mailserver-admin' === $repo) {
                    throw $adminException;
                }
                if ('docker-mailserver' === $repo) {
                    throw $mailserverException;
                }

                return null;
            });

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed to fetch latest admin version: Client error', $output);
        $this->assertStringContainsString('Failed to fetch latest mailserver version: Redirection error', $output);
    }

    public function testExecuteAdminGitHubDecodingError(): void
    {
        $exception = new class('Decoding error') extends \RuntimeException implements DecodingExceptionInterface {
        };

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
            ->willReturnCallback(function ($owner, $repo) use ($exception) {
                if ('mailserver-admin' === $repo) {
                    throw $exception;
                }

                return '2.0.0';
            });

        $this->commandTester->execute([]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Failed to fetch latest admin version: Decoding error', $output);
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

    public function testExecuteCurrentVersionsNullLatestNotNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn(null);
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn(null);

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
}
