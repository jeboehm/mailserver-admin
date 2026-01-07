<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd\DTO;

/**
 * Represents Rspamd health/readiness status.
 */
final readonly class HealthDto
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_WARNING = 'warning';
    public const string STATUS_CRITICAL = 'critical';

    public function __construct(
        public string $status,
        public string $message,
        public ?int $httpStatus = null,
        public ?float $latencyMs = null,
    ) {
    }

    public static function ok(string $message = 'Rspamd is healthy', ?float $latencyMs = null): self
    {
        return new self(self::STATUS_OK, $message, 200, $latencyMs);
    }

    public static function warning(string $message, ?int $httpStatus = null, ?float $latencyMs = null): self
    {
        return new self(self::STATUS_WARNING, $message, $httpStatus, $latencyMs);
    }

    public static function critical(string $message, ?int $httpStatus = null, ?float $latencyMs = null): self
    {
        return new self(self::STATUS_CRITICAL, $message, $httpStatus, $latencyMs);
    }

    public function isOk(): bool
    {
        return self::STATUS_OK === $this->status;
    }

    public function isWarning(): bool
    {
        return self::STATUS_WARNING === $this->status;
    }

    public function isCritical(): bool
    {
        return self::STATUS_CRITICAL === $this->status;
    }
}
