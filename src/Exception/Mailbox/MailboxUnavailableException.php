<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Exception\Mailbox;

/**
 * The IMAP session failed. The message is safe to show to the caller and says whether to retry.
 */
final class MailboxUnavailableException extends \RuntimeException
{
    private function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }

    public static function retryable(): self
    {
        return new self('The mailbox is busy or temporarily unavailable. Retry in a few seconds.', true);
    }

    public static function failed(): self
    {
        return new self('The mailbox could not be read.', false);
    }
}
