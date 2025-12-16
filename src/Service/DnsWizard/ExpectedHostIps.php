<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

final readonly class ExpectedHostIps
{
    /**
     * @param list<string> $ipv4
     * @param list<string> $ipv6
     */
    public function __construct(
        public array $ipv4,
        public array $ipv6,
        public bool $isOverride,
    ) {
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return \array_values(\array_unique([...$this->ipv4, ...$this->ipv6]));
    }
}
