<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd\DTO;

use App\Service\Rspamd\DTO\HealthDto;
use PHPUnit\Framework\TestCase;

class HealthDtoTest extends TestCase
{
    public function testOkFactory(): void
    {
        $dto = HealthDto::ok('All good', 15.5);

        self::assertSame(HealthDto::STATUS_OK, $dto->status);
        self::assertSame('All good', $dto->message);
        self::assertSame(200, $dto->httpStatus);
        self::assertSame(15.5, $dto->latencyMs);
        self::assertTrue($dto->isOk());
        self::assertFalse($dto->isWarning());
        self::assertFalse($dto->isCritical());
    }

    public function testWarningFactory(): void
    {
        $dto = HealthDto::warning('Something is wrong', 500, 100.0);

        self::assertSame(HealthDto::STATUS_WARNING, $dto->status);
        self::assertSame('Something is wrong', $dto->message);
        self::assertSame(500, $dto->httpStatus);
        self::assertSame(100.0, $dto->latencyMs);
        self::assertFalse($dto->isOk());
        self::assertTrue($dto->isWarning());
        self::assertFalse($dto->isCritical());
    }

    public function testCriticalFactory(): void
    {
        $dto = HealthDto::critical('System down', 503);

        self::assertSame(HealthDto::STATUS_CRITICAL, $dto->status);
        self::assertSame('System down', $dto->message);
        self::assertSame(503, $dto->httpStatus);
        self::assertNull($dto->latencyMs);
        self::assertFalse($dto->isOk());
        self::assertFalse($dto->isWarning());
        self::assertTrue($dto->isCritical());
    }
}
