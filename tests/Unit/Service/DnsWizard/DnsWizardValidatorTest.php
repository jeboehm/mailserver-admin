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
use App\Service\DnsWizard\Check\DnsCheckInterface;
use App\Service\DnsWizard\DnsWizardRow;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\DnsWizardValidator;
use App\Service\DnsWizard\ExpectedHostIps;
use PHPUnit\Framework\TestCase;

class DnsWizardValidatorTest extends TestCase
{
    public function testValidateCallsAllChecksForMailHost(): void
    {
        $check1 = $this->createMock(DnsCheckInterface::class);
        $check2 = $this->createMock(DnsCheckInterface::class);

        $row1 = new DnsWizardRow(
            scope: 'mail_host',
            subject: 'mail.example.com',
            recordType: 'A',
            expectedValues: ['1.2.3.4'],
            actualValues: ['1.2.3.4'],
            status: DnsWizardStatus::OK,
            message: 'A record matches',
        );

        $row2 = new DnsWizardRow(
            scope: 'mail_host',
            subject: 'mail.example.com',
            recordType: 'PTR',
            expectedValues: ['mail.example.com'],
            actualValues: ['mail.example.com'],
            status: DnsWizardStatus::OK,
            message: 'PTR record matches',
        );

        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $check1->expects(self::once())
            ->method('validateMailHost')
            ->with('mail.example.com', $expectedHostIps, $expectedAll)
            ->willReturn([$row1]);

        $check2->expects(self::once())
            ->method('validateMailHost')
            ->with('mail.example.com', $expectedHostIps, $expectedAll)
            ->willReturn([$row2]);

        $check1->expects(self::never())
            ->method('validateDomain');

        $check2->expects(self::never())
            ->method('validateDomain');

        $validator = new DnsWizardValidator([$check1, $check2]);
        $result = $validator->validate('mail.example.com', $expectedHostIps, []);

        self::assertCount(2, $result['mailHost']);
        self::assertSame($row1, $result['mailHost'][0]);
        self::assertSame($row2, $result['mailHost'][1]);
        self::assertEmpty($result['domains']);
    }

    public function testValidateCallsAllChecksForDomains(): void
    {
        $check1 = $this->createMock(DnsCheckInterface::class);
        $check2 = $this->createMock(DnsCheckInterface::class);

        $domain = new Domain();
        $domain->setName('example.com');

        $row1 = new DnsWizardRow(
            scope: 'domain',
            subject: 'example.com',
            recordType: 'MX',
            expectedValues: ['mail.example.com'],
            actualValues: ['mail.example.com'],
            status: DnsWizardStatus::OK,
            message: 'MX record matches',
        );

        $row2 = new DnsWizardRow(
            scope: 'domain',
            subject: 'example.com',
            recordType: 'TXT',
            expectedValues: ['v=spf1 -all'],
            actualValues: ['v=spf1 -all'],
            status: DnsWizardStatus::OK,
            message: 'SPF record matches',
        );

        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $check1->expects(self::once())
            ->method('validateMailHost')
            ->with('mail.example.com', $expectedHostIps, $expectedAll)
            ->willReturn([]);

        $check2->expects(self::once())
            ->method('validateMailHost')
            ->with('mail.example.com', $expectedHostIps, $expectedAll)
            ->willReturn([]);

        $check1->expects(self::once())
            ->method('validateDomain')
            ->with('mail.example.com', $expectedAll, $domain)
            ->willReturn([$row1]);

        $check2->expects(self::once())
            ->method('validateDomain')
            ->with('mail.example.com', $expectedAll, $domain)
            ->willReturn([$row2]);

        $validator = new DnsWizardValidator([$check1, $check2]);
        $result = $validator->validate('mail.example.com', $expectedHostIps, [$domain]);

        self::assertEmpty($result['mailHost']);
        self::assertArrayHasKey('example.com', $result['domains']);
        self::assertCount(2, $result['domains']['example.com']);
        self::assertSame($row1, $result['domains']['example.com'][0]);
        self::assertSame($row2, $result['domains']['example.com'][1]);
    }

    public function testValidateNormalizesHostname(): void
    {
        $check = $this->createMock(DnsCheckInterface::class);

        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $check->expects(self::once())
            ->method('validateMailHost')
            ->with('mail.example.com', $expectedHostIps, $expectedAll)
            ->willReturn([]);

        $check->expects(self::never())
            ->method('validateDomain');

        $validator = new DnsWizardValidator([$check]);
        $validator->validate('MAIL.EXAMPLE.COM.', $expectedHostIps, []);
    }

    public function testValidateHandlesMultipleDomains(): void
    {
        $check = $this->createMock(DnsCheckInterface::class);

        $domain1 = new Domain();
        $domain1->setName('example.com');

        $domain2 = new Domain();
        $domain2->setName('test.com');

        $row1 = new DnsWizardRow(
            scope: 'domain',
            subject: 'example.com',
            recordType: 'MX',
            expectedValues: [],
            actualValues: [],
            status: DnsWizardStatus::OK,
            message: 'OK',
        );

        $row2 = new DnsWizardRow(
            scope: 'domain',
            subject: 'test.com',
            recordType: 'MX',
            expectedValues: [],
            actualValues: [],
            status: DnsWizardStatus::OK,
            message: 'OK',
        );

        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $check->expects(self::once())
            ->method('validateMailHost')
            ->willReturn([]);

        $check->expects(self::exactly(2))
            ->method('validateDomain')
            ->willReturnCallback(static function (string $mailname, array $expectedAll, Domain $domain) use ($row1, $row2) {
                return match ($domain->getName()) {
                    'example.com' => [$row1],
                    'test.com' => [$row2],
                    default => [],
                };
            });

        $validator = new DnsWizardValidator([$check]);
        $result = $validator->validate('mail.example.com', $expectedHostIps, [$domain1, $domain2]);

        self::assertArrayHasKey('example.com', $result['domains']);
        self::assertArrayHasKey('test.com', $result['domains']);
        self::assertCount(1, $result['domains']['example.com']);
        self::assertCount(1, $result['domains']['test.com']);
    }

    public function testValidateAggregatesResultsFromMultipleChecks(): void
    {
        $check1 = $this->createMock(DnsCheckInterface::class);
        $check2 = $this->createMock(DnsCheckInterface::class);

        $row1a = new DnsWizardRow(
            scope: 'mail_host',
            subject: 'mail.example.com',
            recordType: 'A',
            expectedValues: [],
            actualValues: [],
            status: DnsWizardStatus::OK,
            message: 'A record',
        );

        $row1b = new DnsWizardRow(
            scope: 'mail_host',
            subject: 'mail.example.com',
            recordType: 'AAAA',
            expectedValues: [],
            actualValues: [],
            status: DnsWizardStatus::OK,
            message: 'AAAA record',
        );

        $row2 = new DnsWizardRow(
            scope: 'mail_host',
            subject: 'mail.example.com',
            recordType: 'PTR',
            expectedValues: [],
            actualValues: [],
            status: DnsWizardStatus::OK,
            message: 'PTR record',
        );

        $expectedHostIps = new ExpectedHostIps(['1.2.3.4'], [], true);
        $expectedAll = ['1.2.3.4'];

        $check1->expects(self::once())
            ->method('validateMailHost')
            ->willReturn([$row1a, $row1b]);

        $check2->expects(self::once())
            ->method('validateMailHost')
            ->willReturn([$row2]);

        $check1->expects(self::never())
            ->method('validateDomain');

        $check2->expects(self::never())
            ->method('validateDomain');

        $validator = new DnsWizardValidator([$check1, $check2]);
        $result = $validator->validate('mail.example.com', $expectedHostIps, []);

        self::assertCount(3, $result['mailHost']);
        self::assertSame($row1a, $result['mailHost'][0]);
        self::assertSame($row1b, $result['mailHost'][1]);
        self::assertSame($row2, $result['mailHost'][2]);
    }
}
