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
 * Represents an action threshold configuration.
 */
final readonly class ActionThresholdDto
{
    public function __construct(
        public string $action,
        public float $threshold,
    ) {
    }
}
