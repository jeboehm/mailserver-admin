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

readonly class DkimRecordCheck implements DnsCheckInterface
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
        if (!$domain->getDkimEnabled()) {
            return [];
        }

        $domainName = $domain->getName();
        $selector = $domain->getDkimSelector();
        $dkimName = \sprintf('%s._domainkey.%s', $selector, $domainName);
        $dkimTxt = $this->dns->lookupTxt($dkimName);

        $nonEmpty = false;

        foreach ($dkimTxt as $txt) {
            if ('' !== \trim($txt)) {
                $nonEmpty = true;
                break;
            }
        }

        return [new DnsWizardRow(
            scope: Scopes::SCOPE_DOMAIN,
            subject: $dkimName,
            recordType: 'TXT',
            expectedValues: ['DKIM selector record (non-empty)'],
            actualValues: $dkimTxt,
            status: $nonEmpty ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
            message: $nonEmpty ? 'DKIM selector record found' : 'DKIM selector record missing or empty',
        )];
    }
}
