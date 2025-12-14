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

    public function testGetAdminVersionSuccessWithVPrefix(): void
    {
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, 'v1.2.3');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetAdminVersionSuccessWithoutVPrefix(): void
    {
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, '1.2.3');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetAdminVersionSuccessWithWhitespace(): void
    {
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, "  v1.2.3  \n");

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetAdminVersionFileNotFound(): void
    {
        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        $this->assertNull($result);
    }

    public function testGetAdminVersionEmptyFile(): void
    {
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, '');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        $this->assertNull($result);
    }

    public function testGetAdminVersionWhitespaceOnly(): void
    {
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, "   \n\t  ");

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        $this->assertNull($result);
    }

    public function testGetAdminVersionMultipleVPrefix(): void
    {
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, 'vv1.2.3');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getAdminVersion();

        // ltrim removes all leading 'v' characters
        $this->assertEquals('1.2.3', $result);
    }

    public function testGetMailserverVersionSuccessWithVPrefix(): void
    {
        $versionFile = $this->tempDir . '/DMS-VERSION';
        file_put_contents($versionFile, 'v2.0.0');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        $this->assertEquals('2.0.0', $result);
    }

    public function testGetMailserverVersionSuccessWithoutVPrefix(): void
    {
        $versionFile = $this->tempDir . '/DMS-VERSION';
        file_put_contents($versionFile, '2.0.0');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        $this->assertEquals('2.0.0', $result);
    }

    public function testGetMailserverVersionSuccessWithWhitespace(): void
    {
        $versionFile = $this->tempDir . '/DMS-VERSION';
        file_put_contents($versionFile, "  v2.0.0  \n");

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        $this->assertEquals('2.0.0', $result);
    }

    public function testGetMailserverVersionFileNotFound(): void
    {
        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        $this->assertNull($result);
    }

    public function testGetMailserverVersionEmptyFile(): void
    {
        $versionFile = $this->tempDir . '/DMS-VERSION';
        file_put_contents($versionFile, '');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        $this->assertNull($result);
    }

    public function testGetMailserverVersionWhitespaceOnly(): void
    {
        $versionFile = $this->tempDir . '/DMS-VERSION';
        file_put_contents($versionFile, "   \n\t  ");

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        $this->assertNull($result);
    }

    public function testGetMailserverVersionMultipleVPrefix(): void
    {
        $versionFile = $this->tempDir . '/DMS-VERSION';
        file_put_contents($versionFile, 'vv2.0.0');

        $service = new ApplicationVersionService($this->tempDir);
        $result = $service->getMailserverVersion();

        // ltrim removes all leading 'v' characters
        $this->assertEquals('2.0.0', $result);
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
