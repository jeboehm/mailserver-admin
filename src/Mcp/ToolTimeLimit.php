<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

final class ToolTimeLimit
{
    /**
     * Only ever raises it. A limit of zero means there is none, and setting one would introduce it.
     */
    public static function raiseTo(int $seconds): void
    {
        $current = (int) \ini_get('max_execution_time');

        if ($current > 0 && $current < $seconds) {
            set_time_limit($seconds);
        }
    }
}
