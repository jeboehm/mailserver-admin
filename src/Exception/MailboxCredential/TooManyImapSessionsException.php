<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Exception\MailboxCredential;

/**
 * The user has as many IMAP sessions open as we allow. Retrying shortly is the right response.
 */
final class TooManyImapSessionsException extends \RuntimeException
{
}
