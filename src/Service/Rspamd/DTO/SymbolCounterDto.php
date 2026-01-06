<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd\DTO;

/**
 * Represents a symbol/rule counter from Rspamd.
 */
final readonly class SymbolCounterDto
{
    public function __construct(
        public string $name,
        public int $hits,
        public float $weight,
        public float $frequency,
        public ?float $averageTime = null,
        public ?string $description = null,
    ) {
    }
}
