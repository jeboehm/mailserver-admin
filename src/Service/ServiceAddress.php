<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

/**
 * The address of another service, as the environment variables hold it.
 */
final readonly class ServiceAddress
{
    private function __construct(
        public string $host,
        public int $port,
    ) {
    }

    /**
     * @param string $address host:port, where an IPv6 host needs brackets
     */
    public static function parse(string $address, int $defaultPort): self
    {
        return 1 === preg_match('/^(.+):(\d+)$/', $address, $parts) && !str_ends_with($parts[1], ':')
            ? new self($parts[1], (int) $parts[2])
            : new self($address, $defaultPort);
    }
}
