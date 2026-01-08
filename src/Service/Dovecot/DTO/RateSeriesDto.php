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
 * Represents a time series of calculated rates for charting.
 */
final readonly class RateSeriesDto
{
    /**
     * @param string                   $counterName The name of the counter these rates are derived from
     * @param string                   $unit        The unit label (e.g., "per minute", "bytes/min")
     * @param list<\DateTimeImmutable> $timestamps  The timestamps for each rate point
     * @param list<float>              $rates       The calculated rates at each point
     */
    public function __construct(
        public string $counterName,
        public string $unit,
        public array $timestamps,
        public array $rates,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getLabels(string $format = 'H:i'): array
    {
        return array_map(
            static fn (\DateTimeImmutable $ts) => $ts->format($format),
            $this->timestamps
        );
    }

    public function isEmpty(): bool
    {
        return empty($this->rates);
    }

    public function getMaxRate(): float
    {
        return empty($this->rates) ? 0.0 : max($this->rates);
    }

    public function getMinRate(): float
    {
        return empty($this->rates) ? 0.0 : min($this->rates);
    }

    public function getAverageRate(): float
    {
        if (empty($this->rates)) {
            return 0.0;
        }

        return array_sum($this->rates) / count($this->rates);
    }
}
