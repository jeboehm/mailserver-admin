<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

/**
 * A DKIM key record as published in DNS, both verbatim and parsed into its tags.
 */
final readonly class DomainKey
{
    /**
     * @param string                $raw  the record exactly as published, for display
     * @param array<string, string> $tags the parsed tag-value pairs
     */
    public function __construct(
        public string $raw,
        public array $tags,
    ) {
    }

    public function getTag(string $name): ?string
    {
        return $this->tags[$name] ?? null;
    }
}
