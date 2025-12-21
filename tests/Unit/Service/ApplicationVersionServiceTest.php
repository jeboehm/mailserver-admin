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
    #[DataProvider('adminVersionProvider')]
    public function testGetAdminVersion(?string $fileContent, ?string $expectedResult, string $description): void
    {
        $service = new ApplicationVersionService(adminVersion: $fileContent);
        $result = $service->getAdminVersion();

        $this->assertEquals($expectedResult, $result, $description);
    }

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
        $service = new ApplicationVersionService(mailserverVersion: $fileContent);
        $result = $service->getMailserverVersion();

        $this->assertEquals($expectedResult, $result, $description);
    }

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
        $service = new ApplicationVersionService(mailserverVersion: 'v2.0.0', adminVersion: 'v1.2.3');
        $adminVersion = $service->getAdminVersion();
        $mailserverVersion = $service->getMailserverVersion();

        $this->assertEquals('1.2.3', $adminVersion);
        $this->assertEquals('2.0.0', $mailserverVersion);
    }
}
