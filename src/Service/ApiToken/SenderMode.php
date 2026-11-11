<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\ApiToken;

/**
 * Which address a token with mail:send or mail:draft sends and drafts as.
 */
enum SenderMode: string
{
    /** The owner's primary address, and only that. */
    case Primary = 'primary';
    /** One owned address, bound when the token is created. */
    case Fixed = 'fixed';
    /** Any owned address, chosen per call, defaulting to the primary address. */
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary address only',
            self::Fixed => 'A fixed address',
            self::Any => 'Any of my addresses',
        };
    }
}
