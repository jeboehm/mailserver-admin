<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Service\Dovecot\DTO\DoveadmHealthDto;
use App\Service\Dovecot\DTO\HealthStatus;
use PHPUnit\Framework\TestCase;

final class DoveadmHealthDtoTest extends TestCase
{
    public function testOk(): void
    {
        $lastFetch = new \DateTimeImmutable('2024-01-01 10:00:00');
        $dto = DoveadmHealthDto::ok($lastFetch);

        self::assertSame(HealthStatus::OK, $dto->status);
        self::assertStringContainsString('reachable and authenticated', $dto->message);
        self::assertSame($lastFetch, $dto->lastSuccessfulFetch);
        self::assertTrue($dto->isHealthy());
    }

    public function testOkWithoutLastFetch(): void
    {
        $dto = DoveadmHealthDto::ok();

        self::assertSame(HealthStatus::OK, $dto->status);
        self::assertNull($dto->lastSuccessfulFetch);
        self::assertTrue($dto->isHealthy());
    }

    public function testConnectionError(): void
    {
        $dto = DoveadmHealthDto::connectionError('Connection refused');

        self::assertSame(HealthStatus::CRITICAL, $dto->status);
        self::assertStringContainsString('Cannot connect', $dto->message);
        self::assertStringContainsString('Connection refused', $dto->message);
        self::assertFalse($dto->isHealthy());
    }

    public function testAuthenticationError(): void
    {
        $dto = DoveadmHealthDto::authenticationError();

        self::assertSame(HealthStatus::CRITICAL, $dto->status);
        self::assertStringContainsString('Authentication failed', $dto->message);
        self::assertFalse($dto->isHealthy());
    }

    public function testFormatError(): void
    {
        $dto = DoveadmHealthDto::formatError('Invalid JSON');

        self::assertSame(HealthStatus::WARNING, $dto->status);
        self::assertStringContainsString('Unexpected response format', $dto->message);
        self::assertStringContainsString('Invalid JSON', $dto->message);
        self::assertFalse($dto->isHealthy());
    }

    public function testNotConfigured(): void
    {
        $dto = DoveadmHealthDto::notConfigured();

        self::assertSame(HealthStatus::WARNING, $dto->status);
        self::assertStringContainsString('not configured', $dto->message);
        self::assertFalse($dto->isHealthy());
    }

    public function testIsHealthyReturnsFalseForWarning(): void
    {
        $dto = DoveadmHealthDto::notConfigured();

        self::assertFalse($dto->isHealthy());
    }

    public function testIsHealthyReturnsFalseForCritical(): void
    {
        $dto = DoveadmHealthDto::connectionError('test');

        self::assertFalse($dto->isHealthy());
    }
}
