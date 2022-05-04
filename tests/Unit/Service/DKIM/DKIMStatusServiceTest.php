<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Unit\Service\DKIM;

use App\Entity\Domain;
use App\Exception\DKIM\DomainKeyNotFoundException;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DKIM\DomainKeyReaderService;
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DKIMStatusServiceTest extends TestCase
{
    private MockObject $domainKeyReaderService;

    private MockObject $formatterService;

    private MockObject $keyGenerationService;

    private DKIMStatusService $instance;

    protected function setUp(): void
    {
        $this->domainKeyReaderService = $this->createMock(DomainKeyReaderService::class);
        $this->formatterService = $this->createMock(FormatterService::class);
        $this->keyGenerationService = $this->createMock(KeyGenerationService::class);
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

        $this->domainKeyReaderService
            ->expects($this->once())
            ->method('getDomainKey')
            ->with('example.com', '20181203')
            ->willReturn(['p' => 'public', 's' => '20181203', 'h' => 'sha256']);

        $this->keyGenerationService
            ->expects($this->once())
            ->method('extractPublicKey')
            ->with('lorem ipsum')
            ->willReturn('public');

        $this->formatterService
            ->expects($this->once())
            ->method('getTXTRecord')
            ->with('public', 'sha256')
            ->willReturn('p=public\; s=20181203\; h=sha256');

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

        $this->domainKeyReaderService
            ->expects($this->once())
            ->method('getDomainKey')
            ->with('example.com', '20181203')
            ->willReturn(['p' => 'public', 's' => '20181203', 'h' => 'sha256']);

        $this->keyGenerationService
            ->expects($this->once())
            ->method('extractPublicKey')
            ->with('lorem ipsum')
            ->willReturn('anotherpublickey');

        $this->formatterService
            ->expects($this->once())
            ->method('getTXTRecord')
            ->with('anotherpublickey', 'sha256')
            ->willReturn('p=anotherpublickey\; s=20181203\; h=sha256');

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

        $this->domainKeyReaderService
            ->expects($this->once())
            ->method('getDomainKey')
            ->with('example.com', '20181203')
            ->willThrowException(new DomainKeyNotFoundException());

        $status = $this->instance->getStatus($domain);

        $this->assertTrue($status->isDkimEnabled());
        $this->assertFalse($status->isDkimRecordFound());
        $this->assertFalse($status->isDkimRecordValid());
        $this->assertEmpty($status->getCurrentRecord());
    }
}
