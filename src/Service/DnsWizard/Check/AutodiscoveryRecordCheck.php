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

readonly class AutodiscoveryRecordCheck implements DnsCheckInterface
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

        return [
            $this->validateA($domainName, 'autoconfig', $expectedAll),
            $this->validateA($domainName, 'autodiscover', $expectedAll),
            $this->validateAOrCname($domainName, 'imap', $expectedAll),
            $this->validateAOrCname($domainName, 'smtp', $expectedAll),
            $this->validateMailconfTxt($domainName),
            $this->validateSrv($domainName, '_imaps._tcp', 993, $mailname),
            $this->validateSrv($domainName, '_submission._tcp', 465, $mailname),
            $this->validateSrv($domainName, '_autodiscover._tcp', 443, \sprintf('autodiscover.%s', $domainName)),
        ];
    }

    /**
     * @param list<string> $expectedAll
     */
    private function validateA(string $domainName, string $subdomain, array $expectedAll): DnsWizardRow
    {
        $name = \sprintf('%s.%s', $subdomain, $domainName);
        $a = $this->dns->lookupA($name);
        $aaaa = $this->dns->lookupAaaa($name);
        $all = [...$a, ...$aaaa];
        $matched = 0 !== \count(\array_intersect($expectedAll, $all));

        return new DnsWizardRow(
            scope: Scopes::SCOPE_DOMAIN,
            subject: $name,
            recordType: 'A',
            expectedValues: $expectedAll,
            actualValues: $all,
            status: $matched ? DnsWizardStatus::OK : DnsWizardStatus::WARNING,
            message: $matched ? 'A record points to expected IP' : 'A record missing or points to wrong IP',
        );
    }

    /**
     * @param list<string> $expectedAll
     */
    private function validateAOrCname(string $domainName, string $subdomain, array $expectedAll): DnsWizardRow
    {
        $name = \sprintf('%s.%s', $subdomain, $domainName);
        $a = $this->dns->lookupA($name);
        $aaaa = $this->dns->lookupAaaa($name);
        $cnames = $this->dns->lookupCname($name);

        $matched = false;
        $actualValues = [];

        // Check A/AAAA records
        $all = [...$a, ...$aaaa];
        if (0 !== \count(\array_intersect($expectedAll, $all))) {
            $matched = true;
        }

        // Check CNAME records and resolve them
        foreach ($cnames as $cname) {
            $actualValues[] = \sprintf('CNAME %s', $cname);
            $cnameA = $this->dns->lookupA($cname);
            $cnameAaaa = $this->dns->lookupAaaa($cname);
            $cnameAll = [...$cnameA, ...$cnameAaaa];
            if (0 !== \count(\array_intersect($expectedAll, $cnameAll))) {
                $matched = true;
            }
        }

        $actualValues = \array_merge($all, $actualValues);

        return new DnsWizardRow(
            scope: Scopes::SCOPE_DOMAIN,
            subject: $name,
            recordType: 'A/CNAME',
            expectedValues: $expectedAll,
            actualValues: $actualValues,
            status: $matched ? DnsWizardStatus::OK : DnsWizardStatus::WARNING,
            message: $matched ? 'A or CNAME record points to expected IP' : 'A or CNAME record missing or points to wrong IP',
        );
    }

    private function validateMailconfTxt(string $domainName): DnsWizardRow
    {
        $expected = \sprintf('mailconf=https://autoconfig.%s/mail/config-v1.1.xml', $domainName);
        $txt = $this->dns->lookupTxt($domainName);

        $found = false;

        foreach ($txt as $value) {
            if (\trim($value) === $expected) {
                $found = true;
                break;
            }
        }

        return new DnsWizardRow(
            scope: Scopes::SCOPE_DOMAIN,
            subject: $domainName,
            recordType: 'TXT',
            expectedValues: [$expected],
            actualValues: $txt,
            status: $found ? DnsWizardStatus::OK : DnsWizardStatus::WARNING,
            message: $found ? 'Mailconf TXT record found' : 'Mailconf TXT record missing',
        );
    }

    private function validateSrv(string $domainName, string $service, int $expectedPort, string $expectedTarget): DnsWizardRow
    {
        $name = \sprintf('%s.%s', $service, $domainName);
        $srvRecords = $this->dns->lookupSrv($name);

        $found = false;
        $actualValues = [];

        foreach ($srvRecords as $record) {
            $target = $this->normalizeHostname($record['target']);
            $expectedTargetNormalized = $this->normalizeHostname($expectedTarget);
            $srvString = \sprintf('%d %d %d %s', $record['priority'], $record['weight'], $record['port'], $record['target']);
            $actualValues[] = $srvString;

            if (
                0 === $record['priority']
                && 0 === $record['weight']
                && $record['port'] === $expectedPort
                && $target === $expectedTargetNormalized
            ) {
                $found = true;
            }
        }

        $expectedString = \sprintf('0 0 %d %s', $expectedPort, $expectedTarget);

        return new DnsWizardRow(
            scope: Scopes::SCOPE_DOMAIN,
            subject: $name,
            recordType: 'SRV',
            expectedValues: [$expectedString],
            actualValues: $actualValues,
            status: $found ? DnsWizardStatus::OK : DnsWizardStatus::WARNING,
            message: $found ? 'SRV record found' : (0 === \count($srvRecords) ? 'SRV record missing' : 'SRV record does not match expected values'),
        );
    }

    private function normalizeHostname(string $host): string
    {
        return \rtrim(\strtolower($host), '.');
    }
}
