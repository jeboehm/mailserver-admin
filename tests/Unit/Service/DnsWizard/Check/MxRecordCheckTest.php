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
use App\Service\DnsWizard\Check\MxRecordCheck;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class MxRecordCheckTest extends TestCase
{
    private MockObject|DnsLookupInterface $dns;
    private MxRecordCheck $check;

    protected function setUp(): void
    {
        $this->dns = $this->createMock(DnsLookupInterface::class);
        $this->check = new MxRecordCheck($this->dns);
    }

    public function testGetDefaultPriority(): void
    {
        self::assertSame(70, MxRecordCheck::getDefaultPriority());
    }

    public function testValidateMailHostReturnsEmptyArray(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, ['1.2.3.4']);

        self::assertEmpty($result);
    }

    public function testValidateDomainWithMxPointingToMailHost(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupMx')
            ->with('example.com')
            ->willReturn(['mail.example.com']);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(Scopes::SCOPE_DOMAIN, $row->scope);
        self::assertSame('example.com', $row->subject);
        self::assertSame('MX', $row->recordType);
        self::assertSame(['mail.example.com', 'or a host resolving to expected IPs'], $row->expectedValues);
        self::assertSame(['mail.example.com'], $row->actualValues);
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertSame('MX points to mail host', $row->message);
    }

    public function testValidateDomainWithMxResolvingToExpectedIps(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupMx')
            ->with('example.com')
            ->willReturn(['mx.example.com']);

        $this->dns->method('lookupA')
            ->with('mx.example.com')
            ->willReturn(['1.2.3.4']);

        $this->dns->method('lookupAaaa')
            ->with('mx.example.com')
            ->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertStringContainsString('resolves to expected host IPs', $row->message);
    }

    public function testValidateDomainWithMxNotResolvingToExpectedIps(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupMx')
            ->with('example.com')
            ->willReturn(['mx.example.com']);

        $this->dns->method('lookupA')
            ->with('mx.example.com')
            ->willReturn(['5.6.7.8']);

        $this->dns->method('lookupAaaa')
            ->with('mx.example.com')
            ->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('No MX record points to the mail host', $row->message);
    }

    public function testValidateDomainWithNoMxRecords(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupMx')
            ->with('example.com')
            ->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('No MX records found', $row->message);
    }

    public function testValidateDomainWithMultipleMxRecords(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupMx')
            ->with('example.com')
            ->willReturn(['mx1.example.com', 'mx2.example.com']);

        $this->dns->method('lookupA')
            ->willReturnCallback(function (string $host) use ($expectedAll) {
                return match ($host) {
                    'mx1.example.com' => ['5.6.7.8'],
                    'mx2.example.com' => $expectedAll,
                    default => [],
                };
            });

        $this->dns->method('lookupAaaa')
            ->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithCaseInsensitiveMatching(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupMx')
            ->with('example.com')
            ->willReturn(['MAIL.EXAMPLE.COM']);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithTrailingDotNormalization(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupMx')
            ->with('example.com')
            ->willReturn(['mail.example.com.']);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }
}
