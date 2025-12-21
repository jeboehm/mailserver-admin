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

readonly class AutodiscoveryRecordCheck
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

        return [
            $this->validateAutodiscoveryTxt($domainName, '_autoconfig', $mailname),
            $this->validateAutodiscoveryTxt($domainName, '_autodiscover', $mailname),
        ];
    }

    private function validateAutodiscoveryTxt(string $domainName, string $prefix, string $mailname): DnsWizardRow
    {
        $name = \sprintf('%s.%s', $prefix, $domainName);
        $expected = \sprintf('mailname=%s', $mailname);
        $txt = $this->dns->lookupTxt($name);

        $found = false;

        foreach ($txt as $value) {
            if (\trim($value) === $expected) {
                $found = true;
                break;
            }
        }

        return new DnsWizardRow(
            scope: Scopes::SCOPE_DOMAIN,
            subject: $name,
            recordType: 'TXT',
            expectedValues: [$expected],
            actualValues: $txt,
            status: $found ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
            message: $found ? 'Auto-discovery TXT record found' : 'Auto-discovery TXT record missing',
        );
    }
}
