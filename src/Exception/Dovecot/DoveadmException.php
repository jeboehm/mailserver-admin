<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Exception\Dovecot;

/**
 * Base exception for Doveadm HTTP API errors.
 */
class DoveadmException extends \RuntimeException
{
}
