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

class DomainKeyReaderService
{
    public function __construct(private DNSResolver $resolver)
    {
    }

    /**
     * @throws DomainKeyNotFoundException
     */
    public function getDomainKey(string $domain, string $selector): array
    {
        $dkimDomain = \sprintf('%s._domainkey.%s', $selector, $domain);
        $result = $this->resolver->resolve($dkimDomain);

        if (isset($result[0]['entries'])) {
            $result[0]['txt'] = implode('', $result[0]['entries']);
        }

        $parts = explode(';', trim($result[0]['txt']));
        $record = [];

        foreach ($parts as $part) {
            [$key, $val] = explode('=', trim($part), 2);
            $record[$key] = $val;
        }

        return $record;
    }
}
