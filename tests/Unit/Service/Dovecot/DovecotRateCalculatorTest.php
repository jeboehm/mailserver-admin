<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Service\Dovecot\DovecotRateCalculator;
use App\Service\Dovecot\DTO\RateSeriesDto;
use App\Service\Dovecot\DTO\StatsDumpDto;
use PHPUnit\Framework\TestCase;

final class DovecotRateCalculatorTest extends TestCase
{
    private DovecotRateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DovecotRateCalculator();
    }

    public function testCalculateRateSeriesReturnsEmptyForLessThanTwoSamples(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 10],
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'num_logins');

        self::assertInstanceOf(RateSeriesDto::class, $result);
        self::assertTrue($result->isEmpty());
        self::assertEmpty($result->rates);
        self::assertEmpty($result->timestamps);
    }

    public function testCalculateRateSeriesReturnsEmptyForEmptySamples(): void
    {
        $result = $this->calculator->calculateRateSeries([], 'num_logins');

        self::assertInstanceOf(RateSeriesDto::class, $result);
        self::assertTrue($result->isEmpty());
    }

    public function testCalculateRateSeriesCalculatesCorrectRate(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 10],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'), // 60 seconds later
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 70], // 60 more logins
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'num_logins', 'per second', 1.0);

        self::assertFalse($result->isEmpty());
        self::assertCount(1, $result->rates);
        self::assertCount(1, $result->timestamps);
        // 60 logins / 60 seconds = 1 per second
        self::assertEqualsWithDelta(1.0, $result->rates[0], 0.001);
    }

    public function testCalculateRateSeriesAppliesMultiplier(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 10],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'), // 60 seconds later
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 70], // 60 more logins
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'num_logins', '/min', 60.0);

        self::assertFalse($result->isEmpty());
        // 60 logins / 60 seconds * 60 = 60 per minute
        self::assertEqualsWithDelta(60.0, $result->rates[0], 0.001);
    }

    public function testCalculateRateSeriesHandlesMissingCounter(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['other' => 10],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['other' => 20],
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'missing_counter');

        self::assertTrue($result->isEmpty());
    }

    public function testCalculateRateSeriesHandlesNegativeDeltaAsZero(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 100],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 50], // Counter decreased (reset)
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'num_logins');

        self::assertFalse($result->isEmpty());
        // Negative delta should be clamped to 0
        self::assertEquals(0.0, $result->rates[0]);
    }

    public function testCalculateRateSeriesHandlesZeroTimeDelta(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 10],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'), // Same time
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 20],
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'num_logins');

        // Zero time delta should result in no rate point
        self::assertTrue($result->isEmpty());
    }

    public function testCalculateMultipleRateSeries(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'auth_successes' => 100,
                    'auth_failures' => 10,
                ],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'auth_successes' => 160, // 60 more
                    'auth_failures' => 20, // 10 more
                ],
            ),
        ];

        $result = $this->calculator->calculateMultipleRateSeries(
            $samples,
            ['auth_successes', 'auth_failures'],
            '/min',
            60.0
        );

        self::assertArrayHasKey('auth_successes', $result);
        self::assertArrayHasKey('auth_failures', $result);
        self::assertInstanceOf(RateSeriesDto::class, $result['auth_successes']);
        self::assertInstanceOf(RateSeriesDto::class, $result['auth_failures']);
        self::assertEqualsWithDelta(60.0, $result['auth_successes']->rates[0], 0.001);
        self::assertEqualsWithDelta(10.0, $result['auth_failures']->rates[0], 0.001);
    }

    public function testCalculateAuthRates(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'auth_successes' => 100,
                    'auth_failures' => 10,
                ],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'auth_successes' => 160,
                    'auth_failures' => 20,
                ],
            ),
        ];

        $result = $this->calculator->calculateAuthRates($samples);

        self::assertArrayHasKey('auth_successes', $result);
        self::assertArrayHasKey('auth_failures', $result);
        self::assertSame('/min', $result['auth_successes']->unit);
        self::assertSame('/min', $result['auth_failures']->unit);
    }

    public function testCalculateIoRates(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'disk_input' => 1000,
                    'disk_output' => 2000,
                    'mail_read_bytes' => 5000,
                ],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'disk_input' => 7000, // 6000 bytes more
                    'disk_output' => 8000, // 6000 bytes more
                    'mail_read_bytes' => 11000, // 6000 bytes more
                ],
            ),
        ];

        $result = $this->calculator->calculateIoRates($samples);

        self::assertArrayHasKey('disk_input', $result);
        self::assertArrayHasKey('disk_output', $result);
        self::assertArrayHasKey('mail_read_bytes', $result);
        self::assertSame('bytes/min', $result['disk_input']->unit);
    }

    public function testCalculateLoginRates(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 10],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 70],
            ),
        ];

        $result = $this->calculator->calculateLoginRates($samples);

        self::assertInstanceOf(RateSeriesDto::class, $result);
        self::assertSame('num_logins', $result->counterName);
        self::assertSame('/min', $result->unit);
        self::assertEqualsWithDelta(60.0, $result->rates[0], 0.001);
    }

    public function testCalculateMailDeliveryRates(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['mail_deliveries' => 100],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['mail_deliveries' => 160],
            ),
        ];

        $result = $this->calculator->calculateMailDeliveryRates($samples);

        self::assertInstanceOf(RateSeriesDto::class, $result);
        self::assertSame('mail_deliveries', $result->counterName);
        self::assertSame('/min', $result->unit);
    }

    public function testCalculateIndexRates(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'idx_read' => 100,
                    'idx_write' => 200,
                    'idx_del' => 50,
                    'idx_iter' => 300,
                ],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'idx_read' => 160,
                    'idx_write' => 260,
                    'idx_del' => 110,
                    'idx_iter' => 360,
                ],
            ),
        ];

        $result = $this->calculator->calculateIndexRates($samples);

        self::assertArrayHasKey('idx_read', $result);
        self::assertArrayHasKey('idx_write', $result);
        self::assertArrayHasKey('idx_del', $result);
        self::assertArrayHasKey('idx_iter', $result);
        self::assertSame('/min', $result['idx_read']->unit);
    }

    public function testCalculateFtsRates(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'fts_read' => 100,
                    'fts_write' => 200,
                    'fts_iter' => 300,
                    'fts_cached_read' => 400,
                ],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'fts_read' => 160,
                    'fts_write' => 260,
                    'fts_iter' => 360,
                    'fts_cached_read' => 460,
                ],
            ),
        ];

        $result = $this->calculator->calculateFtsRates($samples);

        self::assertArrayHasKey('fts_read', $result);
        self::assertArrayHasKey('fts_write', $result);
        self::assertArrayHasKey('fts_iter', $result);
        self::assertArrayHasKey('fts_cached_read', $result);
        self::assertSame('/min', $result['fts_read']->unit);
    }

    public function testGetCurrentValues(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 10],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: [
                    'num_logins' => 70,
                    'connected_sessions' => 5,
                ],
            ),
        ];

        $result = $this->calculator->getCurrentValues($samples, ['num_logins', 'connected_sessions', 'missing']);

        self::assertSame(70, $result['num_logins']);
        self::assertSame(5, $result['connected_sessions']);
        self::assertNull($result['missing']);
    }

    public function testGetCurrentValuesReturnsEmptyForEmptySamples(): void
    {
        $result = $this->calculator->getCurrentValues([], ['num_logins']);

        self::assertNull($result['num_logins']);
    }

    public function testCalculateCacheHitRate(): void
    {
        $sample = new StatsDumpDto(
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: [
                'auth_cache_hits' => 80,
                'auth_cache_misses' => 20,
            ],
        );

        $rate = $this->calculator->calculateCacheHitRate($sample);

        self::assertNotNull($rate);
        // 80 hits / 100 total = 80%
        self::assertEqualsWithDelta(80.0, $rate, 0.001);
    }

    public function testCalculateCacheHitRateReturnsNullWhenMissingCounters(): void
    {
        $sample = new StatsDumpDto(
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: ['auth_cache_hits' => 80],
        );

        $rate = $this->calculator->calculateCacheHitRate($sample);

        self::assertNull($rate);
    }

    public function testCalculateCacheHitRateReturnsNullWhenZeroTotal(): void
    {
        $sample = new StatsDumpDto(
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: [
                'auth_cache_hits' => 0,
                'auth_cache_misses' => 0,
            ],
        );

        $rate = $this->calculator->calculateCacheHitRate($sample);

        self::assertNull($rate);
    }

    public function testCalculateRateSeriesWithMultipleSamples(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 10],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 70], // +60
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:02:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['num_logins' => 130], // +60
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'num_logins', '/min', 60.0);

        self::assertCount(2, $result->rates);
        self::assertCount(2, $result->timestamps);
        // Both should be 60 per minute
        self::assertEqualsWithDelta(60.0, $result->rates[0], 0.001);
        self::assertEqualsWithDelta(60.0, $result->rates[1], 0.001);
    }

    public function testCalculateRateSeriesWithFloatCounters(): void
    {
        $samples = [
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['user_cpu' => 10.5],
            ),
            new StatsDumpDto(
                fetchedAt: new \DateTimeImmutable('2024-01-01 10:01:00'),
                lastUpdateSeconds: null,
                resetTimestamp: null,
                counters: ['user_cpu' => 70.8],
            ),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'user_cpu', 'per second', 1.0);

        self::assertFalse($result->isEmpty());
        // (70.8 - 10.5) / 60 = 1.005 per second
        self::assertEqualsWithDelta(1.005, $result->rates[0], 0.001);
    }
}
