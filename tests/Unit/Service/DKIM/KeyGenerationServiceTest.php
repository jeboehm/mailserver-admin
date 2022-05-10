<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Unit\Service\DKIM;

use App\Service\DKIM\KeyGenerationService;
use LogicException;
use PHPUnit\Framework\TestCase;

class KeyGenerationServiceTest extends TestCase
{
    private KeyGenerationService $instance;

    protected function setUp(): void
    {
        $this->instance = new KeyGenerationService();
    }

    public function testCreateKeyPair(): void
    {
        $keypair = $this->instance->createKeyPair();

        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY', $keypair->getPublic());
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY', $keypair->getPrivate());
    }

    public function testExtractPublicKey(): void
    {
        $keypair = $this->instance->createKeyPair();

        $this->assertEquals($keypair->getPublic(), $this->instance->extractPublicKey($keypair->getPrivate()));
    }

    public function testExtractPublicKeyWithInvalidPrivateKey(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot read private key.');

        $this->instance->extractPublicKey('yolo');
    }
}
