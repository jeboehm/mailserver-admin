<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Exception\MailDraft;

/**
 * The draft was not saved. The message is safe to show to the caller and says whether to retry.
 */
final class MailDraftFailedException extends \RuntimeException
{
    public static function retryable(): self
    {
        return new self('The draft was not saved: the mailbox is busy or temporarily unavailable. Retry in a few seconds.');
    }

    public static function failed(): self
    {
        return new self('The draft was not saved. A retry will probably not help.');
    }
}
