<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

/**
 * Parses a DKIM key record into its tags (RFC 6376, section 3.2).
 *
 * DNS providers normalise records differently, so the parser is deliberately tolerant: it accepts
 * any spacing around separators, ignores empty segments (a trailing semicolon is very common) and
 * skips segments that are not tag-value pairs instead of discarding the whole record.
 */
final readonly class RecordParser
{
    /**
     * @return array<string, string>
     */
    public function parse(string $record): array
    {
        $tags = [];

        foreach (explode(';', $record) as $segment) {
            $pair = explode('=', trim($segment), 2);

            if (2 !== \count($pair)) {
                continue;
            }

            $name = trim($pair[0]);

            if ('' === $name) {
                continue;
            }

            $tags[$name] = trim($pair[1]);
        }

        if (isset($tags['p'])) {
            // RFC 6376 ignores whitespace inside base64 values, which is how providers get away
            // with wrapping or chunking long keys.
            $tags['p'] = (string) preg_replace('/\s+/', '', $tags['p']);
        }

        return $tags;
    }
}
