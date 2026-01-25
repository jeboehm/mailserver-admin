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

readonly class MxRecordCheck implements DnsCheckInterface
{
    public function __construct(private DnsLookupInterface $dns)
    {
    }

    public static function getDefaultPriority(): int
    {
        return 70;
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

        $mxTargets = $this->dns->lookupMx($domainName);
        $mxTargetsNormalized = array_map($this->normalizeHostname(...), $mxTargets);
        $mxOk = false;
        $mxMessage = 'No MX record points to the mail host';

        foreach ($mxTargetsNormalized as $idx => $target) {
            if ($target === $mailname) {
                $mxOk = true;
                $mxMessage = 'MX points to mail host';
                break;
            }

            $a = $this->dns->lookupA($target);
            $aaaa = $this->dns->lookupAaaa($target);
            $resolved = [...$a, ...$aaaa];

            if (0 !== \count(array_intersect($expectedAll, $resolved))) {
                $mxOk = true;
                $mxMessage = \sprintf('MX target "%s" resolves to expected host IPs', $mxTargets[$idx] ?? $target);
                break;
            }
        }

        return [
            new DnsWizardRow(
                scope: Scopes::SCOPE_DOMAIN,
                subject: $domainName,
                recordType: 'MX',
                expectedValues: [$mailname, 'or a host resolving to expected IPs'],
                actualValues: $mxTargets,
                status: $mxOk ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
                message: 0 === \count($mxTargets) ? 'No MX records found' : $mxMessage,
            ),
        ];
    }

    private function normalizeHostname(string $host): string
    {
        return rtrim(strtolower($host), '.');
    }
}
