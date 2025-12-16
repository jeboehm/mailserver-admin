<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

use App\Entity\Domain;

readonly class DnsWizardValidator
{
    private const string SCOPE_MAIL_HOST = 'mail_host';
    private const string SCOPE_DOMAIN = 'domain';

    public function __construct(private DnsLookupInterface $dns)
    {
    }

    /**
     * @param list<Domain> $domains
     *
     * @return array{mailHost:list<DnsWizardRow>, domains:array<string, list<DnsWizardRow>>}
     */
    public function validate(string $mailname, ExpectedHostIps $expectedHostIps, array $domains): array
    {
        $mailnameNormalized = $this->normalizeHostname($mailname);
        $expectedAll = $expectedHostIps->all();

        $mailHostRows = $this->validateMailHost($mailnameNormalized, $expectedHostIps, $expectedAll);

        $domainRows = [];

        foreach ($domains as $domain) {
            $name = $domain->getName();
            $domainRows[$name] = $this->validateDomain($mailnameNormalized, $expectedAll, $domain);
        }

        return [
            'mailHost' => $mailHostRows,
            'domains' => $domainRows,
        ];
    }

    /**
     * @param list<string> $expectedAll
     *
     * @return list<DnsWizardRow>
     */
    private function validateMailHost(string $mailname, ExpectedHostIps $expectedHostIps, array $expectedAll): array
    {
        $a = $this->dns->lookupA($mailname);
        $aaaa = $this->dns->lookupAaaa($mailname);
        $matchedAny = 0 !== \count(\array_intersect($expectedAll, [...$a, ...$aaaa]));

        $rows = [];

        $rows[] = $this->buildAddressRow(
            recordType: 'A',
            mailname: $mailname,
            expected: $expectedHostIps->ipv4,
            actual: $a,
            matchedAny: $matchedAny,
            matchedThis: 0 !== \count(\array_intersect($expectedAll, $a)),
        );

        $rows[] = $this->buildAddressRow(
            recordType: 'AAAA',
            mailname: $mailname,
            expected: $expectedHostIps->ipv6,
            actual: $aaaa,
            matchedAny: $matchedAny,
            matchedThis: 0 !== \count(\array_intersect($expectedAll, $aaaa)),
        );

        foreach ($expectedAll as $ip) {
            $targets = $this->dns->lookupPtr($ip);
            $targetsNormalized = \array_map($this->normalizeHostname(...), $targets);
            $ok = \in_array($mailname, $targetsNormalized, true);

            $rows[] = new DnsWizardRow(
                scope: self::SCOPE_MAIL_HOST,
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
    private function validateDomain(string $mailname, array $expectedAll, Domain $domain): array
    {
        $rows = [];

        $domainName = $domain->getName();

        $mxTargets = $this->dns->lookupMx($domainName);
        $mxTargetsNormalized = \array_map($this->normalizeHostname(...), $mxTargets);
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

            if (0 !== \count(\array_intersect($expectedAll, $resolved))) {
                $mxOk = true;
                $mxMessage = \sprintf('MX target "%s" resolves to expected host IPs', $mxTargets[$idx] ?? $target);
                break;
            }
        }

        $rows[] = new DnsWizardRow(
            scope: self::SCOPE_DOMAIN,
            subject: $domainName,
            recordType: 'MX',
            expectedValues: [$mailname, 'or a host resolving to expected IPs'],
            actualValues: $mxTargets,
            status: $mxOk ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
            message: 0 === \count($mxTargets) ? 'No MX records found' : $mxMessage,
        );

        $domainTxt = $this->dns->lookupTxt($domainName);
        $spf = $this->findPolicy($domainTxt, '/^v=spf1(\s+.+)?$/i');

        $rows[] = new DnsWizardRow(
            scope: self::SCOPE_DOMAIN,
            subject: $domainName,
            recordType: 'TXT',
            expectedValues: ['v=spf1 …'],
            actualValues: $domainTxt,
            status: null !== $spf ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
            message: null !== $spf ? 'SPF policy found' : 'No valid SPF policy found',
        );

        if ($domain->getDkimEnabled()) {
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

            $rows[] = new DnsWizardRow(
                scope: self::SCOPE_DOMAIN,
                subject: $dkimName,
                recordType: 'TXT',
                expectedValues: ['DKIM selector record (non-empty)'],
                actualValues: $dkimTxt,
                status: $nonEmpty ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
                message: $nonEmpty ? 'DKIM selector record found' : 'DKIM selector record missing or empty',
            );
        }

        $dmarcName = \sprintf('_dmarc.%s', $domainName);
        $dmarcTxt = $this->dns->lookupTxt($dmarcName);
        $dmarc = $this->findPolicy($dmarcTxt, '/^v=DMARC1(\s*;.*)?$/i');

        $rows[] = new DnsWizardRow(
            scope: self::SCOPE_DOMAIN,
            subject: $dmarcName,
            recordType: 'TXT',
            expectedValues: ['v=DMARC1 …'],
            actualValues: $dmarcTxt,
            status: null !== $dmarc ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
            message: null !== $dmarc ? 'DMARC policy found' : 'DMARC policy missing',
        );

        $rows[] = $this->validateAutodiscoveryTxt($domainName, '_autoconfig', $mailname);
        $rows[] = $this->validateAutodiscoveryTxt($domainName, '_autodiscover', $mailname);

        return $rows;
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    private function buildAddressRow(string $recordType, string $mailname, array $expected, array $actual, bool $matchedAny, bool $matchedThis): DnsWizardRow
    {
        if (0 === \count($expected)) {
            return new DnsWizardRow(
                scope: self::SCOPE_MAIL_HOST,
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
                scope: self::SCOPE_MAIL_HOST,
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
                scope: self::SCOPE_MAIL_HOST,
                subject: $mailname,
                recordType: $recordType,
                expectedValues: $expected,
                actualValues: $actual,
                status: DnsWizardStatus::WARNING,
                message: \sprintf('No matching %s record, but other address records match', $recordType),
            );
        }

        return new DnsWizardRow(
            scope: self::SCOPE_MAIL_HOST,
            subject: $mailname,
            recordType: $recordType,
            expectedValues: $expected,
            actualValues: $actual,
            status: DnsWizardStatus::ERROR,
            message: \sprintf('No matching %s record for expected IP(s)', $recordType),
        );
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

    private function normalizeHostname(string $host): string
    {
        return \rtrim(\strtolower($host), '.');
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
            scope: self::SCOPE_DOMAIN,
            subject: $name,
            recordType: 'TXT',
            expectedValues: [$expected],
            actualValues: $txt,
            status: $found ? DnsWizardStatus::OK : DnsWizardStatus::ERROR,
            message: $found ? 'Auto-discovery TXT record found' : 'Auto-discovery TXT record missing',
        );
    }
}
