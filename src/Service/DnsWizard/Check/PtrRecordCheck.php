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

readonly class PtrRecordCheck implements DnsCheckInterface
{
    public function __construct(private DnsLookupInterface $dns)
    {
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    public function validateMailHost(string $mailname, ExpectedHostIps $expectedHostIps, array $expectedAll): array
    {
        $rows = [];

        foreach ($expectedAll as $ip) {
            $targets = $this->dns->lookupPtr($ip);
            $targetsNormalized = \array_map($this->normalizeHostname(...), $targets);
            $ok = \in_array($mailname, $targetsNormalized, true);

            $rows[] = new DnsWizardRow(
                scope: Scopes::SCOPE_MAIL_HOST,
                subject: $ip,
                recordType: 'PTR',
                expectedValues: [$mailname],
                actualValues: $targets,
                status: $ok ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
                message: $ok ? 'PTR resolves to mail host' : 'PTR does not resolve to mail host',
            );
        }

        return $rows;
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

    private function normalizeHostname(string $host): string
    {
        return \rtrim(\strtolower($host), '.');
    }
}
