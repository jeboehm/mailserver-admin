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
 * The user has no IMAP access, or what they asked for does not exist in their mailbox.
 */
final class MailboxDeniedException extends \RuntimeException
{
}
