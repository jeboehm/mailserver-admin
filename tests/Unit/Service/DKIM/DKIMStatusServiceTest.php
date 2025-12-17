<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DKIM;

use App\Entity\Domain;
use App\Exception\DKIM\DomainKeyNotFoundException;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DKIM\DomainKeyReaderService;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use PHPUnit\Framework\TestCase;

class DKIMStatusServiceTest extends TestCase
{
    private DomainKeyReaderService $domainKeyReaderService;

    private FormatterService $formatterService;

    private KeyGenerationService $keyGenerationService;

    private DKIMStatusService $instance;

    protected function setUp(): void
    {
        $this->domainKeyReaderService = $this->createStub(DomainKeyReaderService::class);
        $this->formatterService = $this->createStub(FormatterService::class);
        // Add default stub behavior to prevent PHPUnit warnings in tests that don't use these services
        $this->formatterService->method('getTXTRecord')->willReturn('');
        $this->keyGenerationService = $this->createStub(KeyGenerationService::class);
        // Add default stub behavior to prevent PHPUnit warnings in tests that don't use this service
        $this->keyGenerationService->method('extractPublicKey')->willReturn('');
        $this->instance = new DKIMStatusService(
            $this->domainKeyReaderService,
            $this->formatterService,
            $this->keyGenerationService
        );
    }

    public function testDKIMDisabled(): void
    {
        $domain = new Domain();
        $domain->setDkimEnabled(false);

        $status = $this->instance->getStatus($domain);

        $this->assertFalse($status->isDkimEnabled());
        $this->assertFalse($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEmpty($status->getCurrentRecord());
    }

    public function testDKIMEnabledButNoKey(): void
    {
        $domain = new Domain();
        $domain->setDkimEnabled(true);
        $domain->setDkimPrivateKey('');

        $status = $this->instance->getStatus($domain);

        $this->assertTrue($status->isDkimEnabled());
        $this->assertFalse($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEmpty($status->getCurrentRecord());
    }

    public function testDKIMDisabledButPrivateKeyAndSelectorValid(): void
    {
        $domain = new Domain();
        $domain->setDkimEnabled(false);
        $domain->setDkimPrivateKey('lorem ipsum');
        $domain->setDkimSelector('20181203');
        $domain->setName('example.com');

        $this->domainKeyReaderService = $this->createMock(DomainKeyReaderService::class);
        $this->domainKeyReaderService
            ->expects($this->once())
            ->method('getDomainKey')
            ->with('example.com', '20181203')
            ->willReturn(['p' => 'public', 's' => '20181203', 'h' => 'sha256']);

        $this->keyGenerationService = $this->createMock(KeyGenerationService::class);
        $this->keyGenerationService
            ->expects($this->once())
            ->method('extractPublicKey')
            ->with('lorem ipsum')
            ->willReturn('public');

        $this->formatterService = $this->createMock(FormatterService::class);
        $this->formatterService
            ->expects($this->once())
            ->method('getTXTRecord')
            ->with('public', 'sha256')
            ->willReturn('p=public\; s=20181203\; h=sha256');

        $this->instance = new DKIMStatusService(
            $this->domainKeyReaderService,
            $this->formatterService,
            $this->keyGenerationService
        );

        $status = $this->instance->getStatus($domain);

        $this->assertFalse($status->isDkimEnabled());
        $this->assertTrue($status->isDkimRecordFound());
        $this->assertTrue($status->isDkimRecordValid());
        $this->assertEquals('p=public\; s=20181203\; h=sha256', $status->getCurrentRecord());
    }

    public function testDKIMDisabledButPrivateKeyAndSelectorNotValid(): void
    {
        $domain = new Domain();
        $domain->setDkimEnabled(false);
        $domain->setDkimPrivateKey('lorem ipsum');
        $domain->setDkimSelector('20181203');
        $domain->setName('example.com');

        $this->domainKeyReaderService = $this->createMock(DomainKeyReaderService::class);
        $this->domainKeyReaderService
            ->expects($this->once())
            ->method('getDomainKey')
            ->with('example.com', '20181203')
            ->willReturn(['p' => 'public', 's' => '20181203', 'h' => 'sha256']);

        $this->keyGenerationService = $this->createMock(KeyGenerationService::class);
        $this->keyGenerationService
            ->expects($this->once())
            ->method('extractPublicKey')
            ->with('lorem ipsum')
            ->willReturn('anotherpublickey');

        $this->formatterService = $this->createMock(FormatterService::class);
        $this->formatterService
            ->expects($this->once())
            ->method('getTXTRecord')
            ->with('anotherpublickey', 'sha256')
            ->willReturn('p=anotherpublickey\; s=20181203\; h=sha256');

        $this->instance = new DKIMStatusService(
            $this->domainKeyReaderService,
            $this->formatterService,
            $this->keyGenerationService
        );

        $status = $this->instance->getStatus($domain);

        $this->assertFalse($status->isDkimEnabled());
        $this->assertTrue($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEquals('p=public\; s=20181203\; h=sha256', $status->getCurrentRecord());
    }

    public function testDKIMEnabledButRecordNotFound(): void
    {
        $domain = new Domain();
        $domain->setDkimEnabled(true);
        $domain->setDkimPrivateKey('lorem ipsum');
        $domain->setDkimSelector('20181203');
        $domain->setName('example.com');

        $this->domainKeyReaderService = $this->createMock(DomainKeyReaderService::class);
        $this->domainKeyReaderService
            ->expects($this->once())
            ->method('getDomainKey')
            ->with('example.com', '20181203')
            ->willThrowException(new DomainKeyNotFoundException());

        $this->instance = new DKIMStatusService(
            $this->domainKeyReaderService,
            $this->formatterService,
            $this->keyGenerationService
        );

        $status = $this->instance->getStatus($domain);

        $this->assertTrue($status->isDkimEnabled());
        $this->assertFalse($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEmpty($status->getCurrentRecord());
    }
}
