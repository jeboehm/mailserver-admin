<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Helper;

/**
 * Checks a DKIM signature by RFC 6376, as a receiver would, for the relaxed canonicalisation and
 * RSA-SHA256 that Rspamd uses. The caller supplies the key that DNS would hold.
 */
final readonly class DkimVerifier
{
    /**
     * @throws \RuntimeException why it does not hold
     *
     * @return array{d: string, s: string} the tags of a signature that holds
     */
    public static function verify(string $rawMessage, string $domain, string $publicKeyPem): array
    {
        [$head, $body] = explode("\r\n\r\n", $rawMessage, 2) + [1 => ''];
        $headers = self::parseHeaders($head);

        foreach ($headers as $header) {
            if ('dkim-signature' !== strtolower($header['name'])) {
                continue;
            }

            $tags = self::parseTags($header['value']);

            if (($tags['d'] ?? null) === $domain) {
                self::check($tags, $header, $headers, $body, $publicKeyPem);

                return ['d' => $tags['d'], 's' => $tags['s']];
            }
        }

        throw new \RuntimeException(\sprintf('No DKIM signature for %s.', $domain));
    }

    /**
     * @param array<string, string>                    $tags
     * @param array{name: string, value: string}       $signature
     * @param list<array{name: string, value: string}> $headers
     */
    private static function check(array $tags, array $signature, array $headers, string $body, string $publicKeyPem): void
    {
        if ('rsa-sha256' !== ($tags['a'] ?? null)) {
            throw new \RuntimeException(\sprintf('Unexpected algorithm %s.', $tags['a'] ?? 'none'));
        }

        if ('relaxed/relaxed' !== ($tags['c'] ?? null)) {
            throw new \RuntimeException(\sprintf('Unexpected canonicalisation %s.', $tags['c'] ?? 'none'));
        }

        if (isset($tags['l'])) {
            throw new \RuntimeException('The signature only covers part of the body.');
        }

        if (preg_replace('/\s+/', '', $tags['bh']) !== base64_encode(hash('sha256', self::canonicalBody($body), true))) {
            throw new \RuntimeException('The body hash does not match.');
        }

        $signed = '';
        $remaining = $headers;

        foreach (explode(':', preg_replace('/\s+/', '', $tags['h']) ?? '') as $name) {
            // The last occurrence counts first. A name listed once more than it occurs signs nothing.
            foreach (array_reverse($remaining, true) as $i => $header) {
                if (0 === strcasecmp($header['name'], $name)) {
                    $signed .= self::canonicalHeader($header['name'], $header['value']) . "\r\n";
                    unset($remaining[$i]);

                    break;
                }
            }
        }

        $withoutSignature = preg_replace('/(\bb=)[^;]*/', '$1', $signature['value']) ?? '';
        $signed .= self::canonicalHeader($signature['name'], $withoutSignature);

        $signatureBytes = (string) base64_decode((string) preg_replace('/\s+/', '', $tags['b']), true);

        if (1 !== openssl_verify($signed, $signatureBytes, $publicKeyPem, \OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('The signature does not match.');
        }
    }

    /**
     * @return list<array{name: string, value: string}>
     */
    private static function parseHeaders(string $head): array
    {
        $headers = [];

        foreach (preg_split('/\r\n(?![ \t])/', $head) ?: [] as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[] = ['name' => $name, 'value' => $value];
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private static function parseTags(string $value): array
    {
        $tags = [];

        foreach (explode(';', $value) as $tag) {
            if (str_contains($tag, '=')) {
                [$name, $tagValue] = explode('=', $tag, 2);
                $tags[trim($name)] = trim($tagValue);
            }
        }

        return $tags;
    }

    private static function canonicalHeader(string $name, string $value): string
    {
        $unfolded = preg_replace('/\r\n/', '', $value) ?? '';

        return strtolower(trim($name)) . ':' . trim(preg_replace('/[ \t]+/', ' ', $unfolded) ?? '');
    }

    private static function canonicalBody(string $body): string
    {
        $lines = array_map(
            static fn (string $line): string => rtrim(preg_replace('/[ \t]+/', ' ', $line) ?? '', " \t"),
            explode("\r\n", $body),
        );

        while ([] !== $lines && '' === end($lines)) {
            array_pop($lines);
        }

        return [] === $lines ? '' : implode("\r\n", $lines) . "\r\n";
    }
}
