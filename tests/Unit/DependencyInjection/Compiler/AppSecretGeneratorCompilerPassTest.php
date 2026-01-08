<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\DependencyInjection\Compiler;

use App\DependencyInjection\Compiler\AppSecretGeneratorCompilerPass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AppSecretGeneratorCompilerPassTest extends TestCase
{
    private string $tempDir;
    private AppSecretGeneratorCompilerPass $compilerPass;
    private MockObject|ContainerBuilder $containerBuilderMock;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mailserver_admin_test_' . bin2hex(random_bytes(4));
        if (!mkdir($this->tempDir) && !is_dir($this->tempDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $this->tempDir));
        }

        $this->containerBuilderMock = $this->createMock(ContainerBuilder::class);
        $this->compilerPass = new AppSecretGeneratorCompilerPass();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDir . '/app.secret')) {
            unlink($this->tempDir . '/app.secret');
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testProcessWithExistingValidSecret(): void
    {
        $existingSecret = 'existing_valid_secret';
        file_put_contents($this->tempDir . '/app.secret', $existingSecret);

        $this->containerBuilderMock->method('getParameter')
            ->with('kernel.cache_dir')
            ->willReturn($this->tempDir);

        $this->containerBuilderMock->expects($this->once())
            ->method('setParameter')
            ->with('env(APP_SECRET)', $existingSecret);

        $this->compilerPass->process($this->containerBuilderMock);
    }

    public function testProcessWithShortSecret(): void
    {
        $shortSecret = 'short';
        file_put_contents($this->tempDir . '/app.secret', $shortSecret);

        $this->containerBuilderMock->method('getParameter')
            ->with('kernel.cache_dir')
            ->willReturn($this->tempDir);

        $this->containerBuilderMock->expects($this->once())
            ->method('setParameter')
            ->with(
                'env(APP_SECRET)',
                $this->callback(fn ($secret) => is_string($secret) && 12 === strlen($secret))
            );

        $this->compilerPass->process($this->containerBuilderMock);

        $newSecret = file_get_contents($this->tempDir . '/app.secret');
        $this->assertEquals(12, strlen($newSecret));
        $this->assertNotEquals($shortSecret, $newSecret);
    }

    public function testProcessWithNoSecret(): void
    {
        $this->containerBuilderMock->method('getParameter')
            ->with('kernel.cache_dir')
            ->willReturn($this->tempDir);

        $this->containerBuilderMock->expects($this->once())
            ->method('setParameter')
            ->with(
                'env(APP_SECRET)',
                $this->callback(fn ($secret) => is_string($secret) && 12 === strlen($secret))
            );

        $this->compilerPass->process($this->containerBuilderMock);

        $this->assertFileExists($this->tempDir . '/app.secret');
        $newSecret = file_get_contents($this->tempDir . '/app.secret');
        $this->assertEquals(12, strlen($newSecret));
    }

    public function testProcessThrowsExceptionWhenNotWritable(): void
    {
        $this->containerBuilderMock
            ->expects($this->once())
            ->method('getParameter')
            ->with('kernel.cache_dir')
            ->willReturn($this->tempDir);
        $this->containerBuilderMock->method('setParameter')->willReturnCallback(function () {});

        // Make the directory read-only so file creation fails
        chmod($this->tempDir, 0o555);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot write APP_SECRET file');

            $this->compilerPass->process($this->containerBuilderMock);
        } finally {
            // Restore permissions so tearDown can clean up
            chmod($this->tempDir, 0o777);
        }
    }
}
