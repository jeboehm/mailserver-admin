<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox;

use DirectoryTree\ImapEngine\Support\Str;

/**
 * Turns the raw bytes of a message into valid UTF-8, whatever they are.
 *
 * Bodies are usually partial fetches, so input can stop in the middle of an escape, a base64 group
 * or a character. Anything unusable at the end is dropped, because output has to survive json_encode.
 */
final readonly class BodyDecoder
{
    public function decode(string $raw, ?string $encoding, ?string $charset): string
    {
        $bytes = match (strtolower((string) $encoding)) {
            'quoted-printable' => quoted_printable_decode((string) preg_replace('/=[0-9A-Fa-f]?\z/', '', $raw)),
            'base64' => $this->decodeBase64($raw),
            default => $raw,
        };

        return $this->toUtf8($bytes, $charset);
    }

    public function header(string $value): string
    {
        $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $value);
        $decoded = Str::decodeMimeHeader($unfolded);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
    }

    private function decodeBase64(string $raw): string
    {
        $compact = (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw);

        return (string) base64_decode(substr($compact, 0, \strlen($compact) - \strlen($compact) % 4), false);
    }

    private function toUtf8(string $bytes, ?string $charset): string
    {
        $charset = strtoupper($charset ?? 'UTF-8');

        if (!\in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII'], true)) {
            try {
                $converted = mb_convert_encoding($bytes, 'UTF-8', $charset);
                $bytes = false === $converted ? $bytes : $converted;
            } catch (\ValueError) {
                // An unknown charset: all that can be promised is that the result is valid.
            }
        }

        // Removes a character cut off at the end, which mb_scrub would turn into a "?".
        $whole = (string) preg_replace('/(?:[\xC2-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF4][\x80-\xBF]{0,2})\z/', '', $bytes);

        return mb_scrub($whole, 'UTF-8');
    }
}
