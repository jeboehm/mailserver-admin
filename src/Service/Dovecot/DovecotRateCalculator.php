<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot;

use App\Service\Dovecot\DTO\RateSeriesDto;
use App\Service\Dovecot\DTO\StatsDumpDto;

/**
 * Calculates rates from cumulative Dovecot counters.
 *
 * Since Dovecot's statsDump returns cumulative counters,
 * this service computes derived rates (deltas over time) for charting.
 */
readonly class DovecotRateCalculator
{
    public const array INDEX_COUNTERS = [
        'idx_read',
        'idx_write',
        'idx_del',
        'idx_iter',
    ];

    public const array FTS_COUNTERS = [
        'fts_read',
        'fts_write',
        'fts_iter',
        'fts_cached_read',
    ];

    /**
     * Calculate rate series for a single counter.
     *
     * @param list<StatsDumpDto> $samples     The ordered list of samples
     * @param string             $counterName The counter to calculate rates for
     * @param string             $unit        The unit label for display
     * @param float              $multiplier  Multiplier for the rate (e.g., 60 for per-minute)
     */
    public function calculateRateSeries(
        array $samples,
        string $counterName,
        string $unit = 'per second',
        float $multiplier = 1.0,
    ): RateSeriesDto {
        if (\count($samples) < 2) {
            return new RateSeriesDto($counterName, $unit, [], []);
        }

        $timestamps = [];
        $rates = [];
        $prevSample = null;

        foreach ($samples as $sample) {
            if (null === $prevSample) {
                $prevSample = $sample;
                continue;
            }

            $rate = $this->calculateRate($prevSample, $sample, $counterName, $multiplier);

            if (null !== $rate) {
                $timestamps[] = $sample->fetchedAt;
                $rates[] = $rate;
            }

            $prevSample = $sample;
        }

        return new RateSeriesDto($counterName, $unit, $timestamps, $rates);
    }

    /**
     * Calculate rates for multiple counters.
     *
     * @param list<StatsDumpDto> $samples      The ordered list of samples
     * @param list<string>       $counterNames The counters to calculate rates for
     * @param string             $unit         The unit label for display
     * @param float              $multiplier   Multiplier for the rate
     *
     * @return array<string, RateSeriesDto>
     */
    public function calculateMultipleRateSeries(
        array $samples,
        array $counterNames,
        string $unit = 'per second',
        float $multiplier = 1.0,
    ): array {
        $result = [];

        foreach ($counterNames as $counterName) {
            $result[$counterName] = $this->calculateRateSeries(
                $samples,
                $counterName,
                $unit,
                $multiplier
            );
        }

        return $result;
    }

    /**
     * Calculate authentication rates (per minute).
     *
     * @param list<StatsDumpDto> $samples
     *
     * @return array<string, RateSeriesDto>
     */
    public function calculateAuthRates(array $samples): array
    {
        return $this->calculateMultipleRateSeries(
            $samples,
            ['auth_successes', 'auth_failures'],
            '/min',
            60.0
        );
    }

    /**
     * Calculate IO throughput rates (bytes per minute).
     *
     * @param list<StatsDumpDto> $samples
     *
     * @return array<string, RateSeriesDto>
     */
    public function calculateIoRates(array $samples): array
    {
        return $this->calculateMultipleRateSeries(
            $samples,
            ['disk_input', 'disk_output', 'mail_read_bytes'],
            'bytes/min',
            60.0
        );
    }

    /**
     * Calculate login rates (per minute).
     *
     * @param list<StatsDumpDto> $samples
     */
    public function calculateLoginRates(array $samples): RateSeriesDto
    {
        return $this->calculateRateSeries($samples, 'num_logins', '/min', 60.0);
    }

    /**
     * Calculate mail delivery rates (per minute).
     *
     * @param list<StatsDumpDto> $samples
     */
    public function calculateMailDeliveryRates(array $samples): RateSeriesDto
    {
        return $this->calculateRateSeries($samples, 'mail_deliveries', '/min', 60.0);
    }

    /**
     * Calculate index operation rates (per minute).
     *
     * @param list<StatsDumpDto> $samples
     *
     * @return array<string, RateSeriesDto>
     */
    public function calculateIndexRates(array $samples): array
    {
        return $this->calculateMultipleRateSeries(
            $samples,
            self::INDEX_COUNTERS,
            '/min',
            60.0
        );
    }

    /**
     * Calculate FTS operation rates (per minute).
     *
     * @param list<StatsDumpDto> $samples
     *
     * @return array<string, RateSeriesDto>
     */
    public function calculateFtsRates(array $samples): array
    {
        return $this->calculateMultipleRateSeries(
            $samples,
            self::FTS_COUNTERS,
            '/min',
            60.0
        );
    }

    /**
     * Get the current value of gauge-like counters (e.g., connected sessions).
     *
     * @param list<StatsDumpDto> $samples
     * @param list<string>       $counterNames
     *
     * @return array<string, int|float|null>
     */
    public function getCurrentValues(array $samples, array $counterNames): array
    {
        $result = [];
        $latestSample = empty($samples) ? null : $samples[\count($samples) - 1];

        foreach ($counterNames as $counterName) {
            $result[$counterName] = $latestSample?->getCounter($counterName);
        }

        return $result;
    }

    /**
     * Calculate the rate between two samples for a counter.
     *
     * @return float|null The rate, or null if cannot be calculated
     */
    private function calculateRate(
        StatsDumpDto $prev,
        StatsDumpDto $current,
        string $counterName,
        float $multiplier,
    ): ?float {
        $prevValue = $prev->getCounter($counterName);
        $currentValue = $current->getCounter($counterName);

        if (null === $prevValue || null === $currentValue) {
            return null;
        }

        // Calculate time delta in seconds
        $timeDelta = $current->fetchedAt->getTimestamp() - $prev->fetchedAt->getTimestamp();

        if ($timeDelta <= 0) {
            return null;
        }

        // Calculate value delta
        $valueDelta = $currentValue - $prevValue;

        // Clamp negative values to 0 (indicates reset or counter wrap)
        if ($valueDelta < 0) {
            return 0.0;
        }

        // Calculate rate per second, then apply multiplier
        return ($valueDelta / $timeDelta) * $multiplier;
    }
}
