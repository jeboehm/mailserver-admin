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
use App\Service\Dovecot\DTO\StatsDumpDto;
use PHPUnit\Framework\TestCase;

class DovecotRateCalculatorTest extends TestCase
{
    private DovecotRateCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DovecotRateCalculator();
    }

    public function testCalculateRateSeriesWithNoSamples(): void
    {
        $result = $this->calculator->calculateRateSeries([], 'auth_successes');

        self::assertTrue($result->isEmpty());
        self::assertEmpty($result->timestamps);
        self::assertEmpty($result->rates);
    }

    public function testCalculateRateSeriesWithOneSample(): void
    {
        $sample = $this->createSample(['auth_successes' => 100], '2024-01-01 10:00:00');

        $result = $this->calculator->calculateRateSeries([$sample], 'auth_successes');

        self::assertTrue($result->isEmpty());
    }

    public function testCalculateRateSeriesWithTwoSamples(): void
    {
        $sample1 = $this->createSample(['auth_successes' => 100], '2024-01-01 10:00:00');
        $sample2 = $this->createSample(['auth_successes' => 160], '2024-01-01 10:01:00');

        $result = $this->calculator->calculateRateSeries(
            [$sample1, $sample2],
            'auth_successes',
            'per second',
            1.0
        );

        self::assertFalse($result->isEmpty());
        self::assertCount(1, $result->rates);
        // 60 events over 60 seconds = 1 per second
        self::assertEqualsWithDelta(1.0, $result->rates[0], 0.001);
    }

    public function testCalculateRateSeriesWithMultiplier(): void
    {
        $sample1 = $this->createSample(['auth_successes' => 0], '2024-01-01 10:00:00');
        $sample2 = $this->createSample(['auth_successes' => 60], '2024-01-01 10:01:00');

        $result = $this->calculator->calculateRateSeries(
            [$sample1, $sample2],
            'auth_successes',
            '/min',
            60.0 // Convert to per minute
        );

        // 60 events over 60 seconds = 1/sec = 60/min
        self::assertEqualsWithDelta(60.0, $result->rates[0], 0.001);
    }

    public function testCalculateRateSeriesClampsNegativeToZero(): void
    {
        // Simulates a counter reset
        $sample1 = $this->createSample(['auth_successes' => 1000], '2024-01-01 10:00:00');
        $sample2 = $this->createSample(['auth_successes' => 50], '2024-01-01 10:01:00');

        $result = $this->calculator->calculateRateSeries(
            [$sample1, $sample2],
            'auth_successes'
        );

        // Counter went down (reset), should clamp to 0
        self::assertEqualsWithDelta(0.0, $result->rates[0], 0.001);
    }

    public function testCalculateRateSeriesWithMissingCounter(): void
    {
        $sample1 = $this->createSample(['auth_successes' => 100], '2024-01-01 10:00:00');
        $sample2 = $this->createSample(['other_counter' => 200], '2024-01-01 10:01:00');

        $result = $this->calculator->calculateRateSeries(
            [$sample1, $sample2],
            'auth_successes'
        );

        // Cannot calculate rate when counter is missing in one sample
        self::assertTrue($result->isEmpty());
    }

    public function testCalculateRateSeriesWithMultipleSamples(): void
    {
        $samples = [
            $this->createSample(['num_logins' => 0], '2024-01-01 10:00:00'),
            $this->createSample(['num_logins' => 10], '2024-01-01 10:00:10'),
            $this->createSample(['num_logins' => 30], '2024-01-01 10:00:20'),
            $this->createSample(['num_logins' => 40], '2024-01-01 10:00:30'),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'num_logins');

        self::assertCount(3, $result->rates);
        // 10 logins / 10 seconds = 1/sec
        self::assertEqualsWithDelta(1.0, $result->rates[0], 0.001);
        // 20 logins / 10 seconds = 2/sec
        self::assertEqualsWithDelta(2.0, $result->rates[1], 0.001);
        // 10 logins / 10 seconds = 1/sec
        self::assertEqualsWithDelta(1.0, $result->rates[2], 0.001);
    }

    public function testCalculateAuthRates(): void
    {
        $samples = [
            $this->createSample([
                'auth_successes' => 0,
                'auth_failures' => 0,
            ], '2024-01-01 10:00:00'),
            $this->createSample([
                'auth_successes' => 120,
                'auth_failures' => 6,
            ], '2024-01-01 10:01:00'),
        ];

        $result = $this->calculator->calculateAuthRates($samples);

        self::assertArrayHasKey('auth_successes', $result);
        self::assertArrayHasKey('auth_failures', $result);

        // 120 successes over 60 seconds * 60 (to per minute) = 120/min
        self::assertEqualsWithDelta(120.0, $result['auth_successes']->rates[0], 0.001);
        // 6 failures over 60 seconds * 60 = 6/min
        self::assertEqualsWithDelta(6.0, $result['auth_failures']->rates[0], 0.001);
    }

    public function testCalculateIoRates(): void
    {
        $samples = [
            $this->createSample([
                'disk_input' => 0,
                'disk_output' => 0,
                'mail_read_bytes' => 0,
            ], '2024-01-01 10:00:00'),
            $this->createSample([
                'disk_input' => 1024 * 1024, // 1 MB
                'disk_output' => 512 * 1024, // 512 KB
                'mail_read_bytes' => 256 * 1024, // 256 KB
            ], '2024-01-01 10:01:00'),
        ];

        $result = $this->calculator->calculateIoRates($samples);

        self::assertArrayHasKey('disk_input', $result);
        self::assertArrayHasKey('disk_output', $result);
        self::assertArrayHasKey('mail_read_bytes', $result);

        // 1 MB over 60 seconds * 60 = 1 MB/min = 1048576 bytes/min
        self::assertEqualsWithDelta(1048576.0, $result['disk_input']->rates[0], 1.0);
    }

    public function testCalculateLoginRates(): void
    {
        $samples = [
            $this->createSample(['num_logins' => 0], '2024-01-01 10:00:00'),
            $this->createSample(['num_logins' => 30], '2024-01-01 10:01:00'),
        ];

        $result = $this->calculator->calculateLoginRates($samples);

        self::assertSame('num_logins', $result->counterName);
        // 30 logins over 60 seconds * 60 = 30/min
        self::assertEqualsWithDelta(30.0, $result->rates[0], 0.001);
    }

    public function testCalculateCacheHitRate(): void
    {
        $sample = $this->createSample([
            'auth_cache_hits' => 80,
            'auth_cache_misses' => 20,
        ], '2024-01-01 10:00:00');

        $rate = $this->calculator->calculateCacheHitRate($sample);

        // 80 / (80 + 20) * 100 = 80%
        self::assertEqualsWithDelta(80.0, $rate, 0.001);
    }

    public function testCalculateCacheHitRateWithZeroTotal(): void
    {
        $sample = $this->createSample([
            'auth_cache_hits' => 0,
            'auth_cache_misses' => 0,
        ], '2024-01-01 10:00:00');

        $rate = $this->calculator->calculateCacheHitRate($sample);

        self::assertNull($rate);
    }

    public function testCalculateCacheHitRateWithMissingCounters(): void
    {
        $sample = $this->createSample(['auth_cache_hits' => 100], '2024-01-01 10:00:00');

        $rate = $this->calculator->calculateCacheHitRate($sample);

        self::assertNull($rate);
    }

    public function testGetCurrentValues(): void
    {
        $samples = [
            $this->createSample([
                'num_connected_sessions' => 5,
                'num_logins' => 100,
            ], '2024-01-01 10:00:00'),
            $this->createSample([
                'num_connected_sessions' => 8,
                'num_logins' => 150,
            ], '2024-01-01 10:01:00'),
        ];

        $result = $this->calculator->getCurrentValues(
            $samples,
            ['num_connected_sessions', 'num_logins', 'missing_counter']
        );

        self::assertSame(8, $result['num_connected_sessions']);
        self::assertSame(150, $result['num_logins']);
        self::assertNull($result['missing_counter']);
    }

    public function testGetCurrentValuesWithEmptySamples(): void
    {
        $result = $this->calculator->getCurrentValues(
            [],
            ['num_connected_sessions']
        );

        self::assertNull($result['num_connected_sessions']);
    }

    public function testRateSeriesDtoGetters(): void
    {
        $samples = [
            $this->createSample(['auth_successes' => 0], '2024-01-01 10:00:00'),
            $this->createSample(['auth_successes' => 100], '2024-01-01 10:01:00'),
            $this->createSample(['auth_successes' => 150], '2024-01-01 10:02:00'),
            $this->createSample(['auth_successes' => 300], '2024-01-01 10:03:00'),
        ];

        $result = $this->calculator->calculateRateSeries($samples, 'auth_successes');

        // Rates are approximately: 100/60 ≈ 1.67, 50/60 ≈ 0.83, 150/60 ≈ 2.5
        self::assertEqualsWithDelta(2.5, $result->getMaxRate(), 0.01);
        self::assertEqualsWithDelta(0.83, $result->getMinRate(), 0.01);
        self::assertEqualsWithDelta(1.67, $result->getAverageRate(), 0.1);

        $labels = $result->getLabels();
        self::assertCount(3, $labels);
        self::assertSame('10:01', $labels[0]);
    }

    /**
     * @param array<string, int|float> $counters
     */
    private function createSample(array $counters, string $dateTime, ?int $resetTimestamp = null): StatsDumpDto
    {
        return new StatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable($dateTime),
            lastUpdateSeconds: null,
            resetTimestamp: $resetTimestamp,
            counters: $counters,
        );
    }
}
