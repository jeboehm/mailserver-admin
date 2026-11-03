<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox\DTO;

/**
 * What the server knows about a message, and the headers the sender wrote.
 */
final readonly class MessageEnvelope
{
    /**
     * @param list<string>          $flags
     * @param array<string, string> $headers decoded, with lower-case names
     */
    public function __construct(
        public int $uid,
        public array $flags,
        public int $size,
        public ?string $receivedAt,
        public array $headers,
    ) {
    }
}
