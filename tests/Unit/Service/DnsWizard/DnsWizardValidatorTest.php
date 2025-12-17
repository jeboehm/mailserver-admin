<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\DnsWizard;

use App\Entity\Domain;
use App\Service\DnsWizard\Check\AaaaRecordCheck;
use App\Service\DnsWizard\Check\ARecordCheck;
use App\Service\DnsWizard\Check\AutodiscoveryRecordCheck;
use App\Service\DnsWizard\Check\DkimRecordCheck;
use App\Service\DnsWizard\Check\DmarcRecordCheck;
use App\Service\DnsWizard\Check\MxRecordCheck;
use App\Service\DnsWizard\Check\PtrRecordCheck;
use App\Service\DnsWizard\Check\SpfRecordCheck;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\DnsWizardValidator;
use App\Service\DnsWizard\ExpectedHostIps;
use PHPUnit\Framework\TestCase;

class DnsWizardValidatorTest extends TestCase
{
    public function testValidConfiguration(): void
    {
        $dns = new class implements DnsLookupInterface {
            public function lookupA(string $host): array
            {
                return match ($host) {
                    'mail.example.com' => ['1.2.3.4'],
                    default => [],
                };
            }

            public function lookupAaaa(string $host): array
            {
                return [];
            }

            public function lookupMx(string $domain): array
            {
                return ['mail.example.com'];
            }

            public function lookupTxt(string $name): array
            {
                return match ($name) {
                    'example.com' => ['v=spf1 -all'],
                    '_dmarc.example.com' => ['v=DMARC1; p=none'],
                    '_autoconfig.example.com' => ['mailname=mail.example.com'],
                    '_autodiscover.example.com' => ['mailname=mail.example.com'],
                    'dkim._domainkey.example.com' => ['v=DKIM1; p=abc'],
                    default => [],
                };
            }

            public function lookupPtr(string $ip): array
            {
                return ['mail.example.com.'];
            }
        };

        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(true);
        $domain->setDkimSelector('dkim');

        $checks = [
            new ARecordCheck($dns),
            new AaaaRecordCheck($dns),
            new PtrRecordCheck($dns),
            new MxRecordCheck($dns),
            new SpfRecordCheck($dns),
            new DkimRecordCheck($dns),
            new DmarcRecordCheck($dns),
            new AutodiscoveryRecordCheck($dns),
        ];

        $validator = new DnsWizardValidator($checks);
        $expectedIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $result = $validator->validate('mail.example.com', $expectedIps, [$domain]);

        self::assertCount(3, $result['mailHost']);
        self::assertSame(DnsWizardStatus::OK, $result['mailHost'][0]->status); // A
        self::assertSame(DnsWizardStatus::OK, $result['mailHost'][1]->status); // AAAA (no v6 expected)
        self::assertSame(DnsWizardStatus::OK, $result['mailHost'][2]->status); // PTR

        self::assertArrayHasKey('example.com', $result['domains']);
        $rows = $result['domains']['example.com'];
        self::assertSame(DnsWizardStatus::OK, $rows[0]->status); // MX
        self::assertSame(DnsWizardStatus::OK, $rows[1]->status); // SPF
        self::assertSame(DnsWizardStatus::OK, $rows[2]->status); // DKIM
        self::assertSame(DnsWizardStatus::OK, $rows[3]->status); // DMARC
        self::assertSame(DnsWizardStatus::OK, $rows[4]->status); // _autoconfig
        self::assertSame(DnsWizardStatus::OK, $rows[5]->status); // _autodiscover
    }

    public function testMxCanResolveToExpectedIps(): void
    {
        $dns = new class implements DnsLookupInterface {
            public function lookupA(string $host): array
            {
                return match ($host) {
                    'mx.other.tld' => ['1.2.3.4'],
                    default => [],
                };
            }

            public function lookupAaaa(string $host): array
            {
                return [];
            }

            public function lookupMx(string $domain): array
            {
                return ['mx.other.tld'];
            }

            public function lookupTxt(string $name): array
            {
                return match ($name) {
                    'example.com' => ['v=spf1 -all'],
                    '_dmarc.example.com' => ['v=DMARC1; p=none'],
                    '_autoconfig.example.com' => ['mailname=mail.example.com'],
                    '_autodiscover.example.com' => ['mailname=mail.example.com'],
                    default => [],
                };
            }

            public function lookupPtr(string $ip): array
            {
                return ['mail.example.com'];
            }
        };

        $domain = new Domain();
        $domain->setName('example.com');
        $domain->setDkimEnabled(false);

        $checks = [
            new ARecordCheck($dns),
            new AaaaRecordCheck($dns),
            new PtrRecordCheck($dns),
            new MxRecordCheck($dns),
            new SpfRecordCheck($dns),
            new DmarcRecordCheck($dns),
            new AutodiscoveryRecordCheck($dns),
        ];

        $validator = new DnsWizardValidator($checks);
        $expectedIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $result = $validator->validate('mail.example.com', $expectedIps, [$domain]);

        $rows = $result['domains']['example.com'];
        self::assertSame(DnsWizardStatus::OK, $rows[0]->status);
    }
}
