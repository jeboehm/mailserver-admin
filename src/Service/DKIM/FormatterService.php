<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

class FormatterService
{
    /**
     * Builds the DKIM TXT record value.
     *
     * Semicolons are not escaped: the value is meant to be pasted verbatim into a DNS provider,
     * which stores a backslash literally and thereby breaks the record. Administrators editing a
     * BIND zone file by hand have to escape or quote the value themselves.
     */
    public function getTXTRecord(string $publicKey, string $algorithm): string
    {
        $publicKey = preg_replace('#^-+.*?-+$#m', '', $publicKey);
        $publicKey = str_replace(["\r", "\n"], '', $publicKey);

        return \sprintf('v=DKIM1; h=%s; t=s; p=%s', $algorithm, $publicKey);
    }
}
