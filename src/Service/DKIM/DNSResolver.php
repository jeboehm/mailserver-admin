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

class DNSResolver
{
    /**
     * @throws DomainKeyNotFoundException
     */
    public function resolve(string $address): array
    {
        $result = @dns_get_record($address, \DNS_TXT);

        if (!\is_array($result)) {
            throw new DomainKeyNotFoundException(\sprintf('Cannot get DNS records for %s', $address));
        }

        $result = \array_filter(
            $result,
            static fn (array $row) => $row['host'] === $address
        );

        if (0 === \count($result)) {
            throw new DomainKeyNotFoundException(\sprintf('txt record for %s was not found', $address));
        }

        return $result;
    }
}
