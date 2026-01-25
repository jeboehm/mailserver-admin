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
use App\Service\DnsWizard\Check\PtrRecordCheck;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PtrRecordCheckTest extends TestCase
{
    private MockObject|DnsLookupInterface $dns;
    private PtrRecordCheck $check;

    protected function setUp(): void
    {
        $this->dns = $this->createMock(DnsLookupInterface::class);
        $this->check = new PtrRecordCheck($this->dns);
    }

    public function testGetDefaultPriority(): void
    {
        self::assertSame(80, PtrRecordCheck::getDefaultPriority());
    }

    public function testValidateMailHostWithMatchingPtr(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupPtr')
            ->with('1.2.3.4')
            ->willReturn(['mail.example.com.']);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(Scopes::SCOPE_MAIL_HOST, $row->scope);
        self::assertSame('1.2.3.4', $row->subject);
        self::assertSame('PTR', $row->recordType);
        self::assertSame(['mail.example.com'], $row->expectedValues);
        self::assertSame(['mail.example.com.'], $row->actualValues);
        self::assertSame(DnsWizardStatus::OK, $row->status);
        self::assertSame('PTR resolves to mail host', $row->message);
    }

    public function testValidateMailHostWithNonMatchingPtr(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupPtr')
            ->with('1.2.3.4')
            ->willReturn(['other.example.com.']);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::ERROR, $row->status);
        self::assertSame('PTR does not resolve to mail host', $row->message);
    }

    public function testValidateMailHostWithMultipleIps(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4', '5.6.7.8'], [], true);
        $expectedAll = ['1.2.3.4', '5.6.7.8'];

        $this->dns->method('lookupPtr')
            ->willReturnCallback(static function (string $ip) {
                return match ($ip) {
                    '1.2.3.4' => ['mail.example.com.'],
                    '5.6.7.8' => ['other.example.com.'],
                    default => [],
                };
            });

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(2, $result);
        self::assertSame(DnsWizardStatus::OK, $result[0]->status);
        self::assertSame(DnsWizardStatus::ERROR, $result[1]->status);
    }

    public function testValidateMailHostWithCaseInsensitiveMatching(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupPtr')
            ->with('1.2.3.4')
            ->willReturn(['MAIL.EXAMPLE.COM.']);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateMailHostWithTrailingDotNormalization(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupPtr')
            ->with('1.2.3.4')
            ->willReturn(['mail.example.com']);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, $expectedAll);

        self::assertCount(1, $result);
        $row = $result[0];
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainReturnsEmptyArray(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $result = $this->check->validateDomain('mail.example.com', ['1.2.3.4'], $domain);

        self::assertEmpty($result);
    }
}
