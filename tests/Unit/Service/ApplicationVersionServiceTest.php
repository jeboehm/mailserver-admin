<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service;

use App\Service\ApplicationVersionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApplicationVersionServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/' . uniqid('version_test_', true);
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = array_diff(scandir($this->tempDir), ['.', '..']);
            foreach ($files as $file) {
                unlink($this->tempDir . '/' . $file);
            }
            rmdir($this->tempDir);
        }
    }

    #[DataProvider('adminVersionProvider')]
    public function testGetAdminVersion(?string $fileContent, ?string $expectedResult, string $description): void
    {
        if (null !== $fileContent) {
            $versionFile = $this->tempDir . '/VERSION';
            file_put_contents($versionFile, $fileContent);
        }

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        $this->assertEquals($expectedResult, $result, $description);
    }

    /**
     * @return array<int, array{0: string|null, 1: string|null, 2: string}>
     */
    public static function adminVersionProvider(): array
    {
        return [
            'with v prefix' => ['v1.2.3', '1.2.3', 'Should remove v prefix'],
            'without v prefix' => ['1.2.3', '1.2.3', 'Should return version as-is'],
            'with whitespace' => ["  v1.2.3  \n", '1.2.3', 'Should trim whitespace and remove v prefix'],
            'file not found' => [null, null, 'Should return null when file does not exist'],
            'empty file' => ['', null, 'Should return null for empty file'],
            'whitespace only' => ["   \n\t  ", null, 'Should return null for whitespace-only content'],
            'multiple v prefix' => ['vv1.2.3', '1.2.3', 'Should remove all leading v characters'],
            'non version number' => ['main', null, 'Should return null for non version number'],
            'non version number with whitespace' => ["   main  \n", null, 'Should return null for non version number with whitespace'],
        ];
    }

    #[DataProvider('mailserverVersionProvider')]
    public function testGetMailserverVersion(?string $fileContent, ?string $expectedResult, string $description): void
    {
        if (null !== $fileContent) {
            $versionFile = $this->tempDir . '/DMS-VERSION';
            file_put_contents($versionFile, $fileContent);
        }

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        $this->assertEquals($expectedResult, $result, $description);
    }

    /**
     * @return array<int, array{0: string|null, 1: string|null, 2: string}>
     */
    public static function mailserverVersionProvider(): array
    {
        return [
            'with v prefix' => ['v2.0.0', '2.0.0', 'Should remove v prefix'],
            'without v prefix' => ['2.0.0', '2.0.0', 'Should return version as-is'],
            'with whitespace' => ["  v2.0.0  \n", '2.0.0', 'Should trim whitespace and remove v prefix'],
            'file not found' => [null, null, 'Should return null when file does not exist'],
            'empty file' => ['', null, 'Should return null for empty file'],
            'whitespace only' => ["   \n\t  ", null, 'Should return null for whitespace-only content'],
            'multiple v prefix' => ['vv2.0.0', '2.0.0', 'Should remove all leading v characters'],
        ];
    }

    public function testBothVersionsExist(): void
    {
        $adminVersionFile = $this->tempDir . '/VERSION';
        $mailserverVersionFile = $this->tempDir . '/DMS-VERSION';
        file_put_contents($adminVersionFile, 'v1.2.3');
        file_put_contents($mailserverVersionFile, 'v2.0.0');

        $service = new ApplicationVersionService($this->tempDir);
        $adminVersion = $service->getAdminVersion();
        $mailserverVersion = $service->getMailserverVersion();

        $this->assertEquals('1.2.3', $adminVersion);
        $this->assertEquals('2.0.0', $mailserverVersion);
    }
}
