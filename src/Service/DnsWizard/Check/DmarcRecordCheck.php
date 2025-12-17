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

readonly class DmarcRecordCheck implements DnsCheckInterface
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
        $dmarcName = \sprintf('_dmarc.%s', $domainName);
        $dmarcTxt = $this->dns->lookupTxt($dmarcName);
        $dmarc = $this->findPolicy($dmarcTxt, '/^v=DMARC1(\s*;.*)?$/i');

        return [new DnsWizardRow(
            scope: Scopes::SCOPE_DOMAIN,
            subject: $dmarcName,
            recordType: 'TXT',
            expectedValues: ['v=DMARC1 …'],
            actualValues: $dmarcTxt,
            status: null !== $dmarc ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
            message: null !== $dmarc ? 'DMARC policy found' : 'DMARC policy missing',
        )];
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
