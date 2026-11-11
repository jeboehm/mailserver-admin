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
 * The token cannot send or draft as the requested or bound address. Nothing has happened yet, and
 * the message is safe to show to the caller.
 */
final class SenderNotAllowedException extends \RuntimeException
{
}
