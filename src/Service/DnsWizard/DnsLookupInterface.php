<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

interface DnsLookupInterface
{
    /**
     * @return list<string> IPv4 addresses
     */
    public function lookupA(string $host): array;

    /**
     * @return list<string> IPv6 addresses
     */
    public function lookupAaaa(string $host): array;

    /**
     * @return list<string> MX targets (hostnames)
     */
    public function lookupMx(string $domain): array;

    /**
     * @return list<string> TXT strings
     */
    public function lookupTxt(string $name): array;

    /**
     * @return list<string> PTR target hostnames
     */
    public function lookupPtr(string $ip): array;
}
