<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

use App\Exception\DKIM\DomainKeyNotFoundException;
use App\Service\DnsWizard\DnsLookupInterface;

readonly class DomainKeyReaderService
{
    public function __construct(private DnsLookupInterface $resolver)
    {
    }

    /**
     * @throws DomainKeyNotFoundException
     */
    public function getDomainKey(string $domain, string $selector): array
    {
        $dkimDomain = \sprintf('%s._domainkey.%s', $selector, $domain);
        $result = implode('', $this->resolver->lookupTxt($dkimDomain));
        $parts = explode(';', trim($result));
        $record = [];

        foreach ($parts as $part) {
            $keyVal = explode('=', trim($part), 2);

            if (2 !== \count($keyVal)) {
                return [];
            }

            $record[$keyVal[0]] = $keyVal[1];
        }

        return $record;
    }
}
