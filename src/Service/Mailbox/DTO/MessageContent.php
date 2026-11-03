<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox\DTO;

final readonly class MessageContent
{
    /**
     * @param list<MessageAttachment> $attachments
     */
    public function __construct(
        public MessageEnvelope $envelope,
        public string $text,
        public bool $truncated,
        public bool $convertedFromHtml,
        public array $attachments,
    ) {
    }
}
