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

readonly class SpfRecordCheck implements DnsCheckInterface
{
    public function __construct(private DnsLookupInterface $dns)
    {
    }

    public static function getDefaultPriority(): int
    {
        return 60;
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    public function validateMailHost(string $mailname, ExpectedHostIps $expectedHostIps, array $expectedAll): array
    {
        return [];
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    public function validateDomain(string $mailname, array $expectedAll, Domain $domain): array
    {
        $domainName = $domain->getName();
        $domainTxt = $this->dns->lookupTxt($domainName);
        $spf = $this->findPolicy($domainTxt, '/^v=spf1(\s+.+)?$/i');

        return [
            new DnsWizardRow(
                scope: Scopes::SCOPE_DOMAIN,
                subject: $domainName,
                recordType: 'TXT',
                expectedValues: ['v=spf1 …'],
                actualValues: $domainTxt,
                status: null !== $spf ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
                message: null !== $spf ? 'SPF policy found' : 'No valid SPF policy found',
            ),
        ];
    }

    /**
     * @param list<string> $txtValues
     */
    private function findPolicy(array $txtValues, string $pattern): ?string
    {
        foreach ($txtValues as $txt) {
            $txt = \trim($txt);

            if ('' === $txt) {
                continue;
            }

            if (1 === \preg_match($pattern, $txt)) {
                return $txt;
            }
        }

        return null;
    }
}
