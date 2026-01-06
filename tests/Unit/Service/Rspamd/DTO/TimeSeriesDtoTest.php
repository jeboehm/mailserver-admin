<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd\DTO;

use App\Service\Rspamd\DTO\TimeSeriesDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeSeriesDtoTest extends TestCase
{
    public function testConstruction(): void
    {
        $dto = new TimeSeriesDto(
            TimeSeriesDto::TYPE_HOURLY,
            ['00:00', '01:00', '02:00'],
            ['spam' => [10, 20, 30], 'ham' => [100, 200, 300]]
        );

        self::assertSame(TimeSeriesDto::TYPE_HOURLY, $dto->type);
        self::assertCount(3, $dto->labels);
        self::assertCount(2, $dto->datasets);
        self::assertFalse($dto->isEmpty());
    }

    public function testEmpty(): void
    {
        $dto = TimeSeriesDto::empty(TimeSeriesDto::TYPE_DAILY);

        self::assertSame(TimeSeriesDto::TYPE_DAILY, $dto->type);
        self::assertSame([], $dto->labels);
        self::assertSame([], $dto->datasets);
        self::assertTrue($dto->isEmpty());
    }

    #[DataProvider('validTypeProvider')]
    public function testIsValidType(string $type, bool $expected): void
    {
        self::assertSame($expected, TimeSeriesDto::isValidType($type));
    }

    /**
     * @return iterable<array{string, bool}>
     */
    public static function validTypeProvider(): iterable
    {
        yield ['hourly', true];
        yield ['daily', true];
        yield ['weekly', true];
        yield ['monthly', true];
        yield ['yearly', false];
        yield ['invalid', false];
        yield ['', false];
    }
}
