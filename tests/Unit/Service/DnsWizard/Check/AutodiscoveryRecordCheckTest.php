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
use App\Service\DnsWizard\Check\AutodiscoveryRecordCheck;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AutodiscoveryRecordCheckTest extends TestCase
{
    private MockObject|DnsLookupInterface $dns;
    private AutodiscoveryRecordCheck $check;

    protected function setUp(): void
    {
        $this->dns = $this->createMock(DnsLookupInterface::class);
        $this->check = new AutodiscoveryRecordCheck($this->dns);
    }

    public function testGetDefaultPriority(): void
    {
        self::assertSame(60, AutodiscoveryRecordCheck::getDefaultPriority());
    }

    public function testValidateMailHostReturnsEmptyArray(): void
    {
        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);

        $result = $this->check->validateMailHost('mail.example.com', $expectedHostIps, ['1.2.3.4']);

        self::assertEmpty($result);
    }

    public function testValidateDomainWithAllRecordsValid(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')
            ->willReturnCallback(function (string $host) use ($expectedAll) {
                return match ($host) {
                    'autoconfig.example.com', 'autodiscover.example.com', 'imap.example.com', 'smtp.example.com' => $expectedAll,
                    default => [],
                };
            });

        $this->dns->method('lookupAaaa')->willReturn([]);
        $this->dns->method('lookupCname')->willReturn([]);
        $this->dns->method('lookupMx')->willReturn([$mailname]);
        $this->dns->method('lookupTxt')
            ->willReturnCallback(function (string $name) {
                return match ($name) {
                    'example.com' => [\sprintf('mailconf=https://autoconfig.example.com/mail/config-v1.1.xml')],
                    default => [],
                };
            });

        $this->dns->method('lookupSrv')
            ->willReturnCallback(function (string $name) use ($mailname) {
                return match ($name) {
                    '_imaps._tcp.example.com' => [
                        ['priority' => 0, 'weight' => 0, 'port' => 993, 'target' => $mailname],
                    ],
                    '_submission._tcp.example.com' => [
                        ['priority' => 0, 'weight' => 0, 'port' => 465, 'target' => $mailname],
                    ],
                    '_autodiscover._tcp.example.com' => [
                        ['priority' => 0, 'weight' => 0, 'port' => 443, 'target' => 'autodiscover.example.com'],
                    ],
                    default => [],
                };
            });

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(8, $result);

        // Check autoconfig A record
        $row = $result[0];
        self::assertSame(Scopes::SCOPE_DOMAIN, $row->scope);
        self::assertSame('autoconfig.example.com', $row->subject);
        self::assertSame('A', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);

        // Check autodiscover A record
        $row = $result[1];
        self::assertSame('autodiscover.example.com', $row->subject);
        self::assertSame('A', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);

        // Check imap A/CNAME record
        $row = $result[2];
        self::assertSame('imap.example.com', $row->subject);
        self::assertSame('A/CNAME', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);

        // Check smtp A/CNAME record
        $row = $result[3];
        self::assertSame('smtp.example.com', $row->subject);
        self::assertSame('A/CNAME', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);

        // Check mailconf TXT record
        $row = $result[4];
        self::assertSame('example.com', $row->subject);
        self::assertSame('TXT', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);

        // Check _imaps._tcp SRV record
        $row = $result[5];
        self::assertSame('_imaps._tcp.example.com', $row->subject);
        self::assertSame('SRV', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);

        // Check _submission._tcp SRV record
        $row = $result[6];
        self::assertSame('_submission._tcp.example.com', $row->subject);
        self::assertSame('SRV', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);

        // Check _autodiscover._tcp SRV record
        $row = $result[7];
        self::assertSame('_autodiscover._tcp.example.com', $row->subject);
        self::assertSame('SRV', $row->recordType);
        self::assertSame(DnsWizardStatus::OK, $row->status);
    }

    public function testValidateDomainWithMissingARecords(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')->willReturn([]);
        $this->dns->method('lookupAaaa')->willReturn([]);
        $this->dns->method('lookupCname')->willReturn([]);
        $this->dns->method('lookupMx')->willReturn([$mailname]);
        $this->dns->method('lookupTxt')->willReturn([]);
        $this->dns->method('lookupSrv')->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        self::assertCount(8, $result);

        // Check that A records are marked as ERROR
        self::assertSame(DnsWizardStatus::WARNING, $result[0]->status); // autoconfig
        self::assertSame(DnsWizardStatus::WARNING, $result[1]->status); // autodiscover
        self::assertSame(DnsWizardStatus::WARNING, $result[2]->status); // imap
        self::assertSame(DnsWizardStatus::WARNING, $result[3]->status); // smtp
    }

    public function testValidateDomainWithCnameRecords(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')
            ->willReturnCallback(function (string $host) use ($expectedAll) {
                return match ($host) {
                    'autoconfig.example.com', 'autodiscover.example.com' => $expectedAll,
                    'mail.example.com' => $expectedAll, // CNAME target resolves to expected IP
                    default => [],
                };
            });

        $this->dns->method('lookupAaaa')->willReturn([]);
        $this->dns->method('lookupCname')
            ->willReturnCallback(function (string $host) {
                return match ($host) {
                    'imap.example.com', 'smtp.example.com' => ['mail.example.com'],
                    default => [],
                };
            });

        $this->dns->method('lookupMx')->willReturn([$mailname]);
        $this->dns->method('lookupTxt')->willReturn([]);
        $this->dns->method('lookupSrv')->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        // Check that CNAME records are validated correctly
        self::assertSame(DnsWizardStatus::OK, $result[2]->status); // imap via CNAME
        self::assertSame(DnsWizardStatus::OK, $result[3]->status); // smtp via CNAME
    }

    public function testValidateDomainWithMissingMailconfTxt(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')->willReturn($expectedAll);
        $this->dns->method('lookupAaaa')->willReturn([]);
        $this->dns->method('lookupCname')->willReturn([]);
        $this->dns->method('lookupMx')->willReturn([$mailname]);
        $this->dns->method('lookupTxt')->willReturn([]);
        $this->dns->method('lookupSrv')->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        $txtRow = $result[4];
        self::assertSame('TXT', $txtRow->recordType);
        self::assertSame(DnsWizardStatus::WARNING, $txtRow->status);
        self::assertStringContainsString('missing', $txtRow->message);
    }

    public function testValidateDomainWithWrongSrvRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')->willReturn($expectedAll);
        $this->dns->method('lookupAaaa')->willReturn([]);
        $this->dns->method('lookupCname')->willReturn([]);
        $this->dns->method('lookupMx')->willReturn([$mailname]);
        $this->dns->method('lookupTxt')->willReturn([]);
        $this->dns->method('lookupSrv')
            ->willReturnCallback(function (string $name) {
                return match ($name) {
                    '_imaps._tcp.example.com' => [
                        ['priority' => 0, 'weight' => 0, 'port' => 587, 'target' => 'wrong.example.com'], // Wrong port and target
                    ],
                    default => [],
                };
            });

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        $srvRow = $result[5];
        self::assertSame('SRV', $srvRow->recordType);
        self::assertSame(DnsWizardStatus::WARNING, $srvRow->status);
        self::assertStringContainsString('does not match', $srvRow->message);
    }

    public function testValidateDomainWithMissingSrvRecord(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $mailname = 'mail.example.com';
        $expectedAll = ['1.2.3.4'];

        $this->dns->method('lookupA')->willReturn($expectedAll);
        $this->dns->method('lookupAaaa')->willReturn([]);
        $this->dns->method('lookupCname')->willReturn([]);
        $this->dns->method('lookupMx')->willReturn([$mailname]);
        $this->dns->method('lookupTxt')->willReturn([]);
        $this->dns->method('lookupSrv')->willReturn([]);

        $result = $this->check->validateDomain($mailname, $expectedAll, $domain);

        $srvRow = $result[6];
        self::assertSame('SRV', $srvRow->recordType);
        self::assertSame(DnsWizardStatus::WARNING, $srvRow->status);
        self::assertStringContainsString('missing', $srvRow->message);
    }
}
