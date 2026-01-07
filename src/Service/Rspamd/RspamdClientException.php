<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd;

/**
 * Exception thrown when Rspamd client operations fail.
 */
class RspamdClientException extends \RuntimeException
{
    public const int ERROR_CONNECTION = 1;
    public const int ERROR_TIMEOUT = 2;
    public const int ERROR_AUTH = 3;
    public const int ERROR_UPSTREAM = 4;
    public const int ERROR_FORMAT = 5;

    public static function connectionFailed(string $url, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Failed to connect to Rspamd at %s', $url),
            self::ERROR_CONNECTION,
            $previous
        );
    }

    public static function timeout(string $url, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Connection to Rspamd at %s timed out', $url),
            self::ERROR_TIMEOUT,
            $previous
        );
    }

    public static function authenticationFailed(?\Throwable $previous = null): self
    {
        return new self(
            'Authentication to Rspamd controller failed',
            self::ERROR_AUTH,
            $previous
        );
    }

    public static function upstreamError(int $statusCode, string $message, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Rspamd returned error %d: %s', $statusCode, $message),
            self::ERROR_UPSTREAM,
            $previous
        );
    }

    public static function invalidFormat(string $message, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf('Invalid response format from Rspamd: %s', $message),
            self::ERROR_FORMAT,
            $previous
        );
    }
}
