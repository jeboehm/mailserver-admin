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
use App\Service\DnsWizard\Check\SpfRecordCheck;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SpfRecordCheckTest extends TestCase
{
    private MockObject|DnsLookupInterface $dns;
    private SpfRecordCheck $check;

    protected function setUp(): void
    {
        $this->dns = $this->createMock(DnsLookupInterface::class);
        $this->check = new SpfRecordCheck($this->dns);
    }

    public function testGetDefaultPriority(): void
    {
        self::assertSame(60, SpfRecordCheck::getDefaultPriority());
    }

    public function testValidateMailHostReturnsEmptyArray(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, ['1.2.3.4']);

        self::assertEmpty($result);
    }

    public function testValidateDomainWithValidSpfRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn(['v=spf1 -all']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(Scopes::SCOPE_DOMAIN, $row->scope);
        self::assertSame('example.com', $row->subject);
        self::assertSame('TXT', $row->recordType);
        self::assertSame(['v=spf1 …'], $row->expectedValues);
        self::assertSame(['v=spf1 -all'], $row->actualValues);
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertSame('SPF policy found', $row->message);
    }

    public function testValidateDomainWithSpfRecordWithOptions(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn(['v=spf1 ip4:1.2.3.4 -all']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithNoSpfRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn([]);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('No valid SPF policy found', $row->message);
    }

    public function testValidateDomainWithInvalidSpfRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn(['some other txt record']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('No valid SPF policy found', $row->message);
    }

    public function testValidateDomainWithMultipleTxtRecords(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn(['some other txt record', 'v=spf1 -all', 'another record']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithCaseInsensitiveSpf(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn(['V=SPF1 -ALL']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithSpfRecordWithWhitespace(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn(['  v=spf1 -all  ']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithEmptyTxtRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('example.com')
            ->willReturn(['']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
    }
}
