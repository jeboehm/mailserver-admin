<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DnsWizard;

/**
 * The time for a validation has run out. Not the same as a missing record, so a lookup must never
 * answer with an empty result instead.
 */
final class DnsDeadlineExceededException extends \RuntimeException
{
}
