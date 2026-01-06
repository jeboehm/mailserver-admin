<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd;

use App\Service\Rspamd\DTO\ActionDistributionDto;
use App\Service\Rspamd\DTO\KpiValueDto;

/**
 * Parses Prometheus-format metrics from Rspamd /metrics endpoint.
 */
final class RspamdMetricsParser
{
    private const string METRIC_SCANNED = 'rspamd_scanned_total';
    private const string METRIC_LEARNED = 'rspamd_learned_total';
    private const string METRIC_ACTIONS = 'rspamd_actions_total';
    private const string METRIC_SPAM = 'rspamd_spam_total';
    private const string METRIC_HAM = 'rspamd_ham_total';
    private const string METRIC_CONNECTIONS = 'rspamd_connections_total';

    /**
     * Parse all metrics from Prometheus text format.
     *
     * @return array<string, float|int>
     */
    public function parseAll(string $metricsText): array
    {
        $result = [];
        $lines = explode("\n", $metricsText);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            $parsed = $this->parseLine($line);

            if (null !== $parsed) {
                $result[$parsed['key']] = $parsed['value'];
            }
        }

        return $result;
    }

    /**
     * Extract KPI values from metrics.
     *
     * @return array<string, KpiValueDto>
     */
    public function extractKpis(string $metricsText): array
    {
        $metrics = $this->parseAll($metricsText);

        return [
            'scanned' => new KpiValueDto(
                'Messages scanned',
                $this->findMetricValue($metrics, self::METRIC_SCANNED),
                null,
                'fa-envelope'
            ),
            'spam' => new KpiValueDto(
                'Spam detected',
                $this->findMetricValue($metrics, self::METRIC_SPAM),
                null,
                'fa-ban'
            ),
            'ham' => new KpiValueDto(
                'Ham (clean)',
                $this->findMetricValue($metrics, self::METRIC_HAM),
                null,
                'fa-check'
            ),
            'learned' => new KpiValueDto(
                'Learned',
                $this->findMetricValue($metrics, self::METRIC_LEARNED),
                null,
                'fa-graduation-cap'
            ),
            'connections' => new KpiValueDto(
                'Connections',
                $this->findMetricValue($metrics, self::METRIC_CONNECTIONS),
                null,
                'fa-plug'
            ),
        ];
    }

    /**
     * Extract action distribution from metrics as fallback.
     */
    public function extractActionDistribution(string $metricsText): ActionDistributionDto
    {
        $metrics = $this->parseAll($metricsText);
        $actions = [];

        foreach ($metrics as $key => $value) {
            // Match rspamd_actions_total{action="reject"} pattern
            if (str_starts_with($key, self::METRIC_ACTIONS . '{')) {
                $action = $this->extractLabel($key, 'action');

                if (null !== $action) {
                    $actions[$action] = (int) $value;
                }
            }
        }

        return new ActionDistributionDto($actions);
    }

    /**
     * @return array{key: string, value: float|int}|null
     */
    private function parseLine(string $line): ?array
    {
        // Match: metric_name{labels} value or metric_name value
        $pattern = '/^([a-zA-Z_:][a-zA-Z0-9_:]*(?:\{[^}]*\})?)\s+([0-9.eE+-]+)(?:\s+\d+)?$/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        $key = $matches[1];
        $value = $matches[2];

        // Parse value as int or float
        if (str_contains($value, '.') || str_contains($value, 'e') || str_contains($value, 'E')) {
            $numericValue = (float) $value;
        } else {
            $numericValue = (int) $value;
        }

        return [
            'key' => $key,
            'value' => $numericValue,
        ];
    }

    /**
     * Find a metric value by base name (without labels).
     *
     * @param array<string, float|int> $metrics
     */
    private function findMetricValue(array $metrics, string $baseName): int|float|null
    {
        // First try exact match
        if (isset($metrics[$baseName])) {
            return $metrics[$baseName];
        }

        // Try to sum all values with the same base name (for labelled metrics)
        $sum = 0;
        $found = false;

        foreach ($metrics as $key => $value) {
            if ($key === $baseName || str_starts_with($key, $baseName . '{')) {
                $sum += $value;
                $found = true;
            }
        }

        return $found ? $sum : null;
    }

    /**
     * Extract a label value from a metric key.
     * E.g., from 'metric{action="reject"}' extract 'reject' for label 'action'.
     */
    private function extractLabel(string $key, string $labelName): ?string
    {
        $pattern = '/' . preg_quote($labelName, '/') . '="([^"]+)"/';

        if (preg_match($pattern, $key, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
