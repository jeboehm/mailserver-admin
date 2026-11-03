<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailboxCredential;

/**
 * What a client logs in with. Use it at once and only once: it is deleted when the operation that
 * received it ends. In a symfony/mailer DSN the login needs rawurlencode(), because of its "#".
 */
final readonly class ShortLivedCredential
{
    public function __construct(
        public string $login,
        #[\SensitiveParameter]
        public string $secret,
    ) {
    }

    /**
     * @return array{login: string}
     */
    public function __debugInfo(): array
    {
        return ['login' => $this->login];
    }
}
