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
use App\Service\DnsWizard\Check\ARecordCheck;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ARecordCheckTest extends TestCase
{
    private MockObject|DnsLookupInterface $dns;
    private ARecordCheck $check;

    protected function setUp(): void
    {
        $this->dns = $this->createMock(DnsLookupInterface::class);
        $this->check = new ARecordCheck($this->dns);
    }

    public function testGetDefaultPriority(): void
    {
        self::assertSame(100, ARecordCheck::getDefaultPriority());
    }

    public function testValidateMailHostWithMatchingA(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')
            ->with('mail.example.com')
            ->willReturn(['1.2.3.4']);

        $this->dns->method('lookupAaaa')
            ->with('mail.example.com')
            ->willReturn([]);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(Scopes::SCOPE_MAIL_HOST, $row->scope);
        self::assertSame('mail.example.com', $row->subject);
        self::assertSame('A', $row->recordType);
        self::assertSame(['1.2.3.4'], $row->expectedValues);
        self::assertSame(['1.2.3.4'], $row->actualValues);
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertSame('A record matches expected IP(s)', $row->message);
    }

    public function testValidateMailHostWithNonMatchingA(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')
            ->with('mail.example.com')
            ->willReturn(['5.6.7.8']);

        $this->dns->method('lookupAaaa')
            ->with('mail.example.com')
            ->willReturn([]);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('No matching A record for expected IP(s)', $row->message);
    }

    public function testValidateMailHostWithMatchingAaaaButNotA(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')
            ->with('mail.example.com')
            ->willReturn(['5.6.7.8']);

        $this->dns->method('lookupAaaa')
            ->with('mail.example.com')
            ->willReturn(['1.2.3.4']);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::WARNING, $row->status);
        self::assertSame('No matching A record, but other address records match', $row->message);
    }

    public function testValidateMailHostWithNoExpectedIps(): void
    {
        $expectedHostIps = new ExpectedHostIps([], [], true);
        $expectedAll = [];

        $this->dns->method('lookupA')
            ->with('mail.example.com')
            ->willReturn(['1.2.3.4']);

        $this->dns->method('lookupAaaa')
            ->with('mail.example.com')
            ->willReturn([]);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(['(no expected addresses)'], $row->expectedValues);
        self::assertSame(DnsWizardStatus::WARNING, $row->status);
        self::assertSame('No expected host IPs available for validation', $row->message);
    }

    public function testValidateMailHostWithNoExpectedIpsButMatchingAny(): void
    {
        $expectedHostIps = new ExpectedHostIps([], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')
            ->with('mail.example.com')
            ->willReturn(['1.2.3.4']);

        $this->dns->method('lookupAaaa')
            ->with('mail.example.com')
            ->willReturn([]);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertSame('Mail host resolves to expected IP(s)', $row->message);
    }

    public function testValidateDomainReturnsEmptyArray(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $result = $this->check->validateDomain('mail.example.com', ['1.2.3.4'], $domain);

        self::assertEmpty($result);
    }
}
