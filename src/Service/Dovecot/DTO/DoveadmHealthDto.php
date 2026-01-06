<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot\DTO;

/**
 * Represents the health status of the Doveadm HTTP API connection.
 */
final readonly class DoveadmHealthDto
{
    public function __construct(
        public HealthStatus $status,
        public string $message,
        public ?\DateTimeImmutable $lastSuccessfulFetch = null,
    ) {
    }

    public static function ok(?\DateTimeImmutable $lastFetch = null): self
    {
        return new self(
            status: HealthStatus::OK,
            message: 'Doveadm API is reachable and authenticated.',
            lastSuccessfulFetch: $lastFetch,
        );
    }

    public static function connectionError(string $details): self
    {
        return new self(
            status: HealthStatus::CRITICAL,
            message: 'Cannot connect to Doveadm API: ' . $details,
        );
    }

    public static function authenticationError(): self
    {
        return new self(
            status: HealthStatus::CRITICAL,
            message: 'Authentication failed. Please check your DOVEADM_API_KEY_B64 or DOVEADM_BASIC_USER/PASSWORD.',
        );
    }

    public static function formatError(string $details): self
    {
        return new self(
            status: HealthStatus::WARNING,
            message: 'Unexpected response format from Doveadm API: ' . $details,
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            status: HealthStatus::WARNING,
            message: 'Doveadm HTTP API is not configured. Set DOVEADM_HTTP_URL in your environment.',
        );
    }

    public function isHealthy(): bool
    {
        return $this->status === HealthStatus::OK;
    }
}
