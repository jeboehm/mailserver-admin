<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

/**
 * Throw this from a tool for anything the actor may not do and for anything that does not exist.
 * ToolGate answers both with the same message, so tools cannot be used to probe other domains.
 */
final class ToolDeniedException extends \RuntimeException
{
    public const string PUBLIC_MESSAGE = 'Not found or not allowed.';

    public function __construct()
    {
        parent::__construct(self::PUBLIC_MESSAGE);
    }
}
