<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DnsWizard\Check;

use App\Entity\Domain;
use App\Service\DKIM\DKIMStatus;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DnsWizard\Check\DkimRecordCheck;
use App\Service\DnsWizard\DnsWizardRow;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DkimRecordCheckTest extends TestCase
{
    private MockObject|DKIMStatusService $statusService;

    private DkimRecordCheck $check;

    protected function setUp(): void
    {
        $this->statusService = $this->createMock(DKIMStatusService::class);
        $this->check = new DkimRecordCheck($this->statusService);
    }

    public function testValidateDomainWhenDkimDisabled(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(false);
        $domain->setDkimSelector('default');

        $result = $this->check->validateDomain('mail.example.com', [], $domain);

        self::assertEmpty($result);
        $this->statusService->expects($this->never())->method('getStatus');
    }

    public function testValidateDomainWhenDkimEnabledAndRecordValid(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');

        $status = new DKIMStatus(
            dkimEnabled: true,
            dkimRecordFound: true,
            dkimRecordValid: true,
            currentRecord: 'v=DKIM1; p=abc123'
        );

        $this->statusService
            ->expects($this->once())
            ->method('getStatus')
            ->with($domain)
            ->willReturn($status);

        $result = $this->check->validateDomain('mail.example.com', [], $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertInstanceOf(DnsWizardRow::class, $row);
        self::assertSame(Scopes::SCOPE_DOMAIN, $row->scope);
        self::assertSame('dkim._domainkey.example.com', $row->subject);
        self::assertSame('TXT', $row->recordType);
        self::assertSame(['Valid DKIM record'], $row->expectedValues);
        self::assertSame(['v=DKIM1; p=abc123'], $row->actualValues);
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertSame('DKIM record valid', $row->message);
    }

    public function testValidateDomainWhenDkimEnabledAndRecordNotFound(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');

        $status = new DKIMStatus(
            dkimEnabled: true,
            dkimRecordFound: false,
            dkimRecordValid: false,
            currentRecord: ''
        );

        $this->statusService
            ->expects($this->once())
            ->method('getStatus')
            ->with($domain)
            ->willReturn($status);

        $result = $this->check->validateDomain('mail.example.com', [], $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertInstanceOf(DnsWizardRow::class, $row);
        self::assertSame(Scopes::SCOPE_DOMAIN, $row->scope);
        self::assertSame('dkim._domainkey.example.com', $row->subject);
        self::assertSame('TXT', $row->recordType);
        self::assertSame(['Valid DKIM record'], $row->expectedValues);
        self::assertSame([''], $row->actualValues);
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('DKIM record missing or empty', $row->message);
    }

    public function testValidateDomainWhenDkimEnabledAndRecordInvalid(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');

        $status = new DKIMStatus(
            dkimEnabled: true,
            dkimRecordFound: true,
            dkimRecordValid: false,
            currentRecord: 'v=DKIM1; p=wrongkey'
        );

        $this->statusService
            ->expects($this->once())
            ->method('getStatus')
            ->with($domain)
            ->willReturn($status);

        $result = $this->check->validateDomain('mail.example.com', [], $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertInstanceOf(DnsWizardRow::class, $row);
        self::assertSame(Scopes::SCOPE_DOMAIN, $row->scope);
        self::assertSame('dkim._domainkey.example.com', $row->subject);
        self::assertSame('TXT', $row->recordType);
        self::assertSame(['Valid DKIM record'], $row->expectedValues);
        self::assertSame(['v=DKIM1; p=wrongkey'], $row->actualValues);
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('DKIM record mismatch', $row->message);
    }

    public function testValidateMailHostReturnsEmptyArray(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, []);

        self::assertEmpty($result);
        $this->statusService->expects($this->never())->method('getStatus');
    }
}
