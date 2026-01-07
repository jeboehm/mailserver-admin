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
 * Represents a single row from scan history.
 */
final readonly class HistoryRowDto
{
    /**
     * @param list<string> $symbols
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $time,
        public string $action,
        public float $score,
        public float $requiredScore,
        public string $sender,
        public string $recipient,
        public string $ip,
        public int $size,
        public array $symbols,
        public ?string $subject = null,
    ) {
    }
}
