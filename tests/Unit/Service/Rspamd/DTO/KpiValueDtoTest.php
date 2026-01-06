<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd\DTO;

use App\Service\Rspamd\DTO\KpiValueDto;
use PHPUnit\Framework\TestCase;

class KpiValueDtoTest extends TestCase
{
    public function testIntegerValue(): void
    {
        $dto = new KpiValueDto('Messages', 12345, null, 'fa-envelope');

        self::assertSame('Messages', $dto->label);
        self::assertSame(12345, $dto->value);
        self::assertTrue($dto->isAvailable());
        self::assertSame('12,345', $dto->getFormattedValue());
    }

    public function testFloatValue(): void
    {
        $dto = new KpiValueDto('Rate', 99.5, '%', 'fa-percent');

        self::assertSame(99.5, $dto->value);
        self::assertTrue($dto->isAvailable());
        self::assertSame('99.50 %', $dto->getFormattedValue());
    }

    public function testNullValue(): void
    {
        $dto = new KpiValueDto('Unknown', null);

        self::assertNull($dto->value);
        self::assertFalse($dto->isAvailable());
        self::assertSame('n/a', $dto->getFormattedValue());
    }
}
