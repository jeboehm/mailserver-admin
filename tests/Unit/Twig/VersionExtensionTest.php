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
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('adminVersionProvider')]
    public function testGetAdminVersion(?string $serviceReturn, ?string $expectedResult, string $description): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn($serviceReturn);

        $this->gitHubTagService->expects($this->never())->method('getLatestTag');

        $result = $this->extension->getAdminVersion();

        $this->assertEquals($expectedResult, $result, $description);
    }

    public static function adminVersionProvider(): array
    {
        return [
            'delegates to service' => ['1.2.3', '1.2.3', 'Should return version from service'],
            'returns null when service returns null' => [null, null, 'Should return null when service returns null'],
        ];
    }

    #[DataProvider('mailserverVersionProvider')]
    public function testGetMailserverVersion(?string $serviceReturn, ?string $expectedResult, string $description): void
    {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn($serviceReturn);

        $this->gitHubTagService->expects($this->never())->method('getLatestTag');

        $result = $this->extension->getMailserverVersion();

        $this->assertEquals($expectedResult, $result, $description);
    }

    public static function mailserverVersionProvider(): array
    {
        return [
            'delegates to service' => ['2.0.0', '2.0.0', 'Should return version from service'],
            'returns null when service returns null' => [null, null, 'Should return null when service returns null'],
        ];
    }

    #[DataProvider('adminUpdateAvailableProvider')]
    public function testIsAdminUpdateAvailable(
        ?string $currentVersion,
        ?string $latestVersion,
        bool $expectedResult,
        string $description
    ): void {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getAdminVersion')
            ->willReturn($currentVersion);

        if (null !== $currentVersion) {
            $this->gitHubTagService
                ->expects($this->once())
                ->method('getLatestTag')
                ->with('jeboehm', 'mailserver-admin')
                ->willReturn($latestVersion);
        } else {
            $this->gitHubTagService
                ->expects($this->never())
                ->method('getLatestTag');
        }

        $result = $this->extension->isAdminUpdateAvailable();

        $this->assertEquals($expectedResult, $result, $description);
    }

    public static function adminUpdateAvailableProvider(): array
    {
        return [
            'returns false when current version is null' => [null, null, false, 'Should return false when current version is null'],
            'returns false when latest version is null' => ['1.2.3', null, false, 'Should return false when latest version is null'],
            'returns false when versions match' => ['1.2.3', '1.2.3', false, 'Should return false when versions match'],
            'returns true when versions differ' => ['1.2.2', '1.2.3', true, 'Should return true when versions differ'],
        ];
    }

    #[DataProvider('mailserverUpdateAvailableProvider')]
    public function testIsMailserverUpdateAvailable(
        ?string $currentVersion,
        ?string $latestVersion,
        bool $expectedResult,
        string $description
    ): void {
        $this->applicationVersionService
            ->expects($this->once())
            ->method('getMailserverVersion')
            ->willReturn($currentVersion);

        if (null !== $currentVersion) {
            $this->gitHubTagService
                ->expects($this->once())
                ->method('getLatestTag')
                ->with('jeboehm', 'docker-mailserver')
                ->willReturn($latestVersion);
        } else {
            $this->gitHubTagService
                ->expects($this->never())
                ->method('getLatestTag');
        }

        $result = $this->extension->isMailserverUpdateAvailable();

        $this->assertEquals($expectedResult, $result, $description);
    }

    public static function mailserverUpdateAvailableProvider(): array
    {
        return [
            'returns false when current version is null' => [null, null, false, 'Should return false when current version is null'],
            'returns false when latest version is null' => ['2.0.0', null, false, 'Should return false when latest version is null'],
            'returns false when versions match' => ['2.0.0', '2.0.0', false, 'Should return false when versions match'],
            'returns true when versions differ' => ['1.9.9', '2.0.0', true, 'Should return true when versions differ'],
        ];
    }
}
