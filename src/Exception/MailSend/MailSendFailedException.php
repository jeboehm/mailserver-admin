<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Exception\MailSend;

/**
 * Submission failed. The message is safe to show to the caller and says whether to retry.
 */
final class MailSendFailedException extends \RuntimeException
{
    public static function retryable(): self
    {
        return new self('The mail was not sent: the mail server is busy or temporarily unavailable. Retry in a few seconds.');
    }

    public static function refused(int $code): self
    {
        return new self(\sprintf('The mail was not sent: the mail server refused it with code %d.', $code));
    }

    public static function outcomeUnknown(): self
    {
        return new self('The connection to the mail server was lost while sending, so the mail may have been sent. Ask the recipient before sending it again.');
    }
}
