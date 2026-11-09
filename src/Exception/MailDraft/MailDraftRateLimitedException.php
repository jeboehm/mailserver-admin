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
 * The token reached its draft limit. Nothing was tried.
 */
final class MailDraftRateLimitedException extends \RuntimeException
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(\sprintf('The draft was not saved: the draft limit is reached. Retry in %d seconds.', $retryAfterSeconds));
    }
}
