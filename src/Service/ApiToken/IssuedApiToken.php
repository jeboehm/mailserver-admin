<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\ApiToken;

use App\Entity\ApiToken;

/**
 * The only place the secret exists after issuing. It cannot be recovered later.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        public ApiToken $token,
        public string $secret,
    ) {
    }
}
