<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard\Check;

use App\Entity\Domain;
use App\Service\DnsWizard\DnsLookupInterface;
use App\Service\DnsWizard\DnsWizardRow;
use App\Service\DnsWizard\DnsWizardStatus;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\Scopes;

readonly class AaaaRecordCheck implements DnsCheckInterface
{
    public function __construct(private DnsLookupInterface $dns)
    {
    }

    public static function getDefaultPriority(): int
    {
        return 90;
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    public function validateMailHost(string $mailname, ExpectedHostIps $expectedHostIps, array $expectedAll): array
    {
        $a = $this->dns->lookupA($mailname);
        $aaaa = $this->dns->lookupAaaa($mailname);
        $matchedAny = 0 !== \count(array_intersect($expectedAll, [...$a, ...$aaaa]));
        $matchedThis = 0 !== \count(array_intersect($expectedAll, $aaaa));

        return [
            $this->buildAddressRow(
                recordType: 'AAAA',
                mailname: $mailname,
                expected: $expectedHostIps->ipv6,
                actual: $aaaa,
                matchedAny: $matchedAny,
                matchedThis: $matchedThis,
            ),
        ];
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    public function validateDomain(string $mailname, array $expectedAll, Domain $domain): array
    {
        return [];
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function buildAddressRow(
        string $recordType,
        string $mailname,
        array $expected,
        array $actual,
        bool $matchedAny,
        bool $matchedThis
    ): DnsWizardRow {
        if (0 === \count($expected)) {
            return new DnsWizardRow(
                scope: Scopes::SCOPE_MAIL_HOST,
                subject: $mailname,
                recordType: $recordType,
                expectedValues: ['(no expected addresses)'],
                actualValues: $actual,
                status: $matchedAny ? DnsWizardStatus::OK : DnsWizardStatus::WARNING,
                message: $matchedAny ? 'Mail host resolves to expected IP(s)' : 'No expected host IPs available for validation',
            );
        }

        if ($matchedThis) {
            return new DnsWizardRow(
                scope: Scopes::SCOPE_MAIL_HOST,
                subject: $mailname,
                recordType: $recordType,
                expectedValues: $expected,
                actualValues: $actual,
                status: DnsWizardStatus::OK,
                message: \sprintf('%s record matches expected IP(s)', $recordType),
            );
        }

        if ($matchedAny) {
            return new DnsWizardRow(
                scope: Scopes::SCOPE_MAIL_HOST,
                subject: $mailname,
                recordType: $recordType,
                expectedValues: $expected,
                actualValues: $actual,
                status: DnsWizardStatus::WARNING,
                message: \sprintf('No matching %s record, but other address records match', $recordType),
            );
        }

        return new DnsWizardRow(
            scope: Scopes::SCOPE_MAIL_HOST,
            subject: $mailname,
            recordType: $recordType,
            expectedValues: $expected,
            actualValues: $actual,
            status: DnsWizardStatus::ERROR,
            message: \sprintf('No matching %s record for expected IP(s)', $recordType),
        );
    }
}
