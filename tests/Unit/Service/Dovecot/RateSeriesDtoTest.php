<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Service\Dovecot\DTO\RateSeriesDto;
use PHPUnit\Framework\TestCase;

final class RateSeriesDtoTest extends TestCase
{
    public function testConstructor(): void
    {
        $timestamps = [
            new \DateTimeImmutable('2024-01-01 10:00:00'),
            new \DateTimeImmutable('2024-01-01 10:01:00'),
        ];
        $rates = [10.0, 20.0];

        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: $timestamps,
            rates: $rates,
        );

        self::assertSame('num_logins', $dto->counterName);
        self::assertSame('/min', $dto->unit);
        self::assertSame($timestamps, $dto->timestamps);
        self::assertSame($rates, $dto->rates);
    }

    public function testGetLabels(): void
    {
        $timestamps = [
            new \DateTimeImmutable('2024-01-01 10:00:00'),
            new \DateTimeImmutable('2024-01-01 10:01:00'),
        ];
        $rates = [10.0, 20.0];

        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: $timestamps,
            rates: $rates,
        );

        $labels = $dto->getLabels();

        self::assertSame(['10:00', '10:01'], $labels);
    }

    public function testGetLabelsWithCustomFormat(): void
    {
        $timestamps = [
            new \DateTimeImmutable('2024-01-01 10:00:00'),
            new \DateTimeImmutable('2024-01-01 10:01:00'),
        ];
        $rates = [10.0, 20.0];

        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: $timestamps,
            rates: $rates,
        );

        $labels = $dto->getLabels('Y-m-d H:i:s');

        self::assertSame(['2024-01-01 10:00:00', '2024-01-01 10:01:00'], $labels);
    }

    public function testIsEmpty(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [],
            rates: [],
        );

        self::assertTrue($dto->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenHasData(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [new \DateTimeImmutable()],
            rates: [10.0],
        );

        self::assertFalse($dto->isEmpty());
    }

    public function testGetMaxRate(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
                new \DateTimeImmutable('2024-01-01 10:02:00'),
            ],
            rates: [10.0, 30.0, 20.0],
        );

        self::assertSame(30.0, $dto->getMaxRate());
    }

    public function testGetMaxRateReturnsZeroForEmpty(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [],
            rates: [],
        );

        self::assertSame(0.0, $dto->getMaxRate());
    }

    public function testGetMinRate(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
                new \DateTimeImmutable('2024-01-01 10:02:00'),
            ],
            rates: [10.0, 30.0, 20.0],
        );

        self::assertSame(10.0, $dto->getMinRate());
    }

    public function testGetMinRateReturnsZeroForEmpty(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [],
            rates: [],
        );

        self::assertSame(0.0, $dto->getMinRate());
    }

    public function testGetAverageRate(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [
                new \DateTimeImmutable('2024-01-01 10:00:00'),
                new \DateTimeImmutable('2024-01-01 10:01:00'),
                new \DateTimeImmutable('2024-01-01 10:02:00'),
            ],
            rates: [10.0, 20.0, 30.0],
        );

        // (10 + 20 + 30) / 3 = 20
        self::assertEqualsWithDelta(20.0, $dto->getAverageRate(), 0.001);
    }

    public function testGetAverageRateReturnsZeroForEmpty(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [],
            rates: [],
        );

        self::assertSame(0.0, $dto->getAverageRate());
    }

    public function testGetAverageRateWithSingleValue(): void
    {
        $dto = new RateSeriesDto(
            counterName: 'num_logins',
            unit: '/min',
            timestamps: [new \DateTimeImmutable()],
            rates: [42.0],
        );

        self::assertSame(42.0, $dto->getAverageRate());
    }
}
