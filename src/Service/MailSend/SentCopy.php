<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

/**
 * What became of the copy in the Sent mailbox. The mail itself has been sent in all three cases.
 */
final readonly class SentCopy
{
    private function __construct(
        public SentCopyStatus $status,
        public ?string $mailbox = null,
        public bool $temporary = false,
    ) {
    }

    public static function saved(string $mailbox): self
    {
        return new self(SentCopyStatus::Saved, $mailbox);
    }

    /**
     * The user has no IMAP access, so there is nowhere to put a copy.
     */
    public static function skipped(): self
    {
        return new self(SentCopyStatus::Skipped);
    }

    public static function failed(bool $temporary): self
    {
        return new self(SentCopyStatus::Failed, temporary: $temporary);
    }
}
