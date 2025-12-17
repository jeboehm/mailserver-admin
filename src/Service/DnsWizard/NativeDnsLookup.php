<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

final class NativeDnsLookup implements DnsLookupInterface
{
    public function lookupA(string $host): array
    {
        $rows = @dns_get_record($host, \DNS_A);

        if (!\is_array($rows)) {
            return [];
        }

        $ips = [];

        foreach ($rows as $row) {
            $ip = $row['ip'] ?? null;

            if (\is_string($ip) && '' !== $ip) {
                $ips[] = $ip;
            }
        }

        return \array_values(\array_unique($ips));
    }

    public function lookupAaaa(string $host): array
    {
        $rows = @dns_get_record($host, \DNS_AAAA);

        if (!\is_array($rows)) {
            return [];
        }

        $ips = [];

        foreach ($rows as $row) {
            $ip = $row['ipv6'] ?? null;

            if (\is_string($ip) && '' !== $ip) {
                $ips[] = $ip;
            }
        }

        return \array_values(\array_unique($ips));
    }

    public function lookupMx(string $domain): array
    {
        $rows = @dns_get_record($domain, \DNS_MX);

        if (!\is_array($rows)) {
            return [];
        }

        $targets = [];

        foreach ($rows as $row) {
            $target = $row['target'] ?? null;

            if (\is_string($target) && '' !== $target) {
                $targets[] = $target;
            }
        }

        return \array_values(\array_unique($targets));
    }

    public function lookupTxt(string $name): array
    {
        $rows = @dns_get_record($name, \DNS_TXT);

        if (!\is_array($rows)) {
            return [];
        }

        $values = [];

        foreach ($rows as $row) {
            $txt = $row['txt'] ?? null;

            if (\is_string($txt) && '' !== $txt) {
                $values[] = $txt;
            }
        }

        return \array_values(\array_unique($values));
    }

    public function lookupPtr(string $ip): array
    {
        $reverseName = $this->toReverseName($ip);

        if (null === $reverseName) {
            return [];
        }

        $rows = @dns_get_record($reverseName, \DNS_PTR);

        if (!\is_array($rows)) {
            return [];
        }

        $targets = [];

        foreach ($rows as $row) {
            $target = $row['target'] ?? null;

            if (\is_string($target) && '' !== $target) {
                $targets[] = $target;
            }
        }

        return \array_values(\array_unique($targets));
    }

    private function toReverseName(string $ip): ?string
    {
        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $octets = \explode('.', $ip);

            if (4 !== \count($octets)) {
                return null;
            }

            return \sprintf('%s.in-addr.arpa', \implode('.', \array_reverse($octets)));
        }

        if (false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);

            if (!\is_string($packed)) {
                return null;
            }

            $hex = \bin2hex($packed);
            $nibbles = \str_split($hex, 1);
            $reversed = \array_reverse($nibbles);

            return \sprintf('%s.ip6.arpa', \implode('.', $reversed));
        }

        return null;
    }
}
