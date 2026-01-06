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
use App\Service\DnsWizard\Check\DmarcRecordCheck;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DmarcRecordCheckTest extends TestCase
{
    private MockObject|DnsLookupInterface $dns;
    private DmarcRecordCheck $check;

    protected function setUp(): void
    {
        $this->dns = $this->createMock(DnsLookupInterface::class);
        $this->check = new DmarcRecordCheck($this->dns);
    }

    public function testGetDefaultPriority(): void
    {
        self::assertSame(40, DmarcRecordCheck::getDefaultPriority());
    }

    public function testValidateMailHostReturnsEmptyArray(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, ['1.2.3.4']);

        self::assertEmpty($result);
    }

    public function testValidateDomainWithValidDmarcRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('_dmarc.example.com')
            ->willReturn(['v=DMARC1; p=none']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(Scopes::SCOPE_DOMAIN, $row->scope);
        self::assertSame('_dmarc.example.com', $row->subject);
        self::assertSame('TXT', $row->recordType);
        self::assertSame(['v=DMARC1 …'], $row->expectedValues);
        self::assertSame(['v=DMARC1; p=none'], $row->actualValues);
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertSame('DMARC policy found', $row->message);
    }

    public function testValidateDomainWithDmarcRecordWithOptions(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('_dmarc.example.com')
            ->willReturn(['v=DMARC1; p=quarantine; rua=mailto:dmarc@example.com']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithNoDmarcRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('_dmarc.example.com')
            ->willReturn([]);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('DMARC policy missing', $row->message);
    }

    public function testValidateDomainWithInvalidDmarcRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('_dmarc.example.com')
            ->willReturn(['some other txt record']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('DMARC policy missing', $row->message);
    }

    public function testValidateDomainWithMultipleTxtRecords(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('_dmarc.example.com')
            ->willReturn(['some other txt record', 'v=DMARC1; p=none', 'another record']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithCaseInsensitiveDmarc(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('_dmarc.example.com')
            ->willReturn(['v=dmarc1; p=none']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithDmarcRecordWithWhitespace(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupTxt')
            ->with('_dmarc.example.com')
            ->willReturn(['  v=DMARC1; p=none  ']);

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
            ->with('_dmarc.example.com')
            ->willReturn(['']);

        $result = $this->check->validateDomain('mail.example.com', $expectedAll, $domain);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
    }
}
