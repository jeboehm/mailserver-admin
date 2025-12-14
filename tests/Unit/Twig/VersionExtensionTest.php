<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Twig;

use App\Service\ApplicationVersionService;
use App\Service\GitHubTagService;
use App\Twig\VersionExtension;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class VersionExtensionTest extends TestCase
{
    private MockObject|ApplicationVersionService $applicationVersionService;
    private MockObject|GitHubTagService $gitHubTagService;
    private VersionExtension $extension;

    protected function setUp(): void
    {
        $this->applicationVersionService = $this->createMock(ApplicationVersionService::class);
        $this->gitHubTagService = $this->createMock(GitHubTagService::class);
        $this->extension = new VersionExtension(
            $this->applicationVersionService,
            $this->gitHubTagService
        );
    }

    public function testGetAdminVersionDelegatesToService(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.3');

        $result = $this->extension->getAdminVersion();

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetAdminVersionReturnsNullWhenServiceReturnsNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn(null);

        $result = $this->extension->getAdminVersion();

        $this->assertNull($result);
    }

    public function testGetMailserverVersionDelegatesToService(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('2.0.0');

        $result = $this->extension->getMailserverVersion();

        $this->assertEquals('2.0.0', $result);
    }

    public function testGetMailserverVersionReturnsNullWhenServiceReturnsNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn(null);

        $result = $this->extension->getMailserverVersion();

        $this->assertNull($result);
    }

    public function testIsAdminUpdateAvailableReturnsFalseWhenCurrentVersionIsNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn(null);

        $this->gitHubTagService
            ->expects($this->never())
            ->method('getLatestTag');

        $result = $this->extension->isAdminUpdateAvailable();

        $this->assertFalse($result);
    }

    public function testIsAdminUpdateAvailableReturnsFalseWhenLatestVersionIsNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.3');

        $this->gitHubTagService
            ->expects($this->once())
            ->method('getLatestTag')
            ->with('jeboehm', 'mailserver-admin')
            ->willReturn(null);

        $result = $this->extension->isAdminUpdateAvailable();

        $this->assertFalse($result);
    }

    public function testIsAdminUpdateAvailableReturnsFalseWhenVersionsMatch(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.3');

        $this->gitHubTagService
            ->expects($this->once())
            ->method('getLatestTag')
            ->with('jeboehm', 'mailserver-admin')
            ->willReturn('1.2.3');

        $result = $this->extension->isAdminUpdateAvailable();

        $this->assertFalse($result);
    }

    public function testIsAdminUpdateAvailableReturnsTrueWhenVersionsDiffer(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn('1.2.2');

        $this->gitHubTagService
            ->expects($this->once())
            ->method('getLatestTag')
            ->with('jeboehm', 'mailserver-admin')
            ->willReturn('1.2.3');

        $result = $this->extension->isAdminUpdateAvailable();

        $this->assertTrue($result);
    }

    public function testIsMailserverUpdateAvailableReturnsFalseWhenCurrentVersionIsNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn(null);

        $this->gitHubTagService
            ->expects($this->never())
            ->method('getLatestTag');

        $result = $this->extension->isMailserverUpdateAvailable();

        $this->assertFalse($result);
    }

    public function testIsMailserverUpdateAvailableReturnsFalseWhenLatestVersionIsNull(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('2.0.0');

        $this->gitHubTagService
            ->expects($this->once())
            ->method('getLatestTag')
            ->with('jeboehm', 'docker-mailserver')
            ->willReturn(null);

        $result = $this->extension->isMailserverUpdateAvailable();

        $this->assertFalse($result);
    }

    public function testIsMailserverUpdateAvailableReturnsFalseWhenVersionsMatch(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('2.0.0');

        $this->gitHubTagService
            ->expects($this->once())
            ->method('getLatestTag')
            ->with('jeboehm', 'docker-mailserver')
            ->willReturn('2.0.0');

        $result = $this->extension->isMailserverUpdateAvailable();

        $this->assertFalse($result);
    }

    public function testIsMailserverUpdateAvailableReturnsTrueWhenVersionsDiffer(): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn('1.9.9');

        $this->gitHubTagService
            ->expects($this->once())
            ->method('getLatestTag')
            ->with('jeboehm', 'docker-mailserver')
            ->willReturn('2.0.0');

        $result = $this->extension->isMailserverUpdateAvailable();

        $this->assertTrue($result);
    }
}
