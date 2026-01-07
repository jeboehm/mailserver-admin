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
use App\Service\Rspamd\DTO\ActionThresholdDto;
use App\Service\Rspamd\DTO\HealthDto;
use App\Service\Rspamd\DTO\HistoryRowDto;
use App\Service\Rspamd\DTO\KpiValueDto;
use App\Service\Rspamd\DTO\RspamdSummaryDto;
use App\Service\Rspamd\DTO\SymbolCounterDto;
use App\Service\Rspamd\DTO\TimeSeriesDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service layer for Rspamd statistics with caching support.
 */
final readonly class RspamdStatsService
{
    private const int DEFAULT_CACHE_TTL = 10;
    private const int GRAPH_CACHE_TTL = 30;
    private const int HISTORY_CACHE_TTL = 5;

    public function __construct(
        private RspamdControllerClient $client,
        private CacheInterface $cacheApp,
        #[Autowire('%env(default:rspamd_cache_ttl_default:int:RSPAMD_CACHE_TTL_SECONDS)%')]
        private int $cacheTtl,
    ) {
    }

    /**
     * Get complete summary for the dashboard.
     */
    public function getSummary(): RspamdSummaryDto
    {
        return $this->cacheApp->get(
            'rspamd_summary',
            function (ItemInterface $item): RspamdSummaryDto {
                $item->expiresAfter($this->getCacheTtl());

                $health = $this->client->ping();

                if (!$health->isOk()) {
                    return new RspamdSummaryDto(
                        $health,
                        $this->getEmptyKpis(),
                        ActionDistributionDto::empty(),
                        new \DateTimeImmutable()
                    );
                }

                $kpis = $this->fetchKpis();
                $actionDistribution = $this->fetchActionDistribution();

                return new RspamdSummaryDto(
                    $health,
                    $kpis,
                    $actionDistribution,
                    new \DateTimeImmutable()
                );
            }
        );
    }

    /**
     * Get health status only.
     */
    public function getHealth(): HealthDto
    {
        return $this->cacheApp->get(
            'rspamd_health',
            function (ItemInterface $item): HealthDto {
                $item->expiresAfter($this->getCacheTtl());

                return $this->client->ping();
            }
        );
    }

    /**
     * Get time series data for throughput charts.
     */
    public function getThroughputSeries(string $type): TimeSeriesDto
    {
        if (!TimeSeriesDto::isValidType($type)) {
            return TimeSeriesDto::empty($type);
        }

        return $this->cacheApp->get(
            'rspamd_throughput_' . $type,
            function (ItemInterface $item) use ($type): TimeSeriesDto {
                $item->expiresAfter(self::GRAPH_CACHE_TTL);

                try {
                    $data = $this->client->graph($type);

                    return $this->parseGraphData($data, $type);
                } catch (RspamdClientException $e) {
                    return TimeSeriesDto::empty($type);
                }
            }
        );
    }

    /**
     * Get action distribution for pie charts.
     */
    public function getActionDistribution(): ActionDistributionDto
    {
        return $this->cacheApp->get(
            'rspamd_action_distribution',
            function (ItemInterface $item): ActionDistributionDto {
                $item->expiresAfter($this->getCacheTtl());

                return $this->fetchActionDistribution();
            }
        );
    }

    /**
     * Get action thresholds.
     *
     * @return list<ActionThresholdDto>
     */
    public function getActionThresholds(): array
    {
        return $this->cacheApp->get(
            'rspamd_action_thresholds',
            function (ItemInterface $item): array {
                $item->expiresAfter($this->getCacheTtl());

                try {
                    $data = $this->client->actions();

                    return $this->parseActionsThresholds($data);
                } catch (RspamdClientException) {
                    return [];
                }
            }
        );
    }

    /**
     * Get top symbols/counters.
     *
     * @return list<SymbolCounterDto>
     */
    public function getTopSymbols(int $limit = 20): array
    {
        return $this->cacheApp->get(
            'rspamd_top_symbols_' . $limit,
            function (ItemInterface $item) use ($limit): array {
                $item->expiresAfter($this->getCacheTtl());

                try {
                    $data = $this->client->counters();

                    return $this->parseCounters($data, $limit);
                } catch (RspamdClientException) {
                    return [];
                }
            }
        );
    }

    /**
     * Get recent scan history.
     *
     * @return list<HistoryRowDto>
     */
    public function getHistory(int $limit = 50): array
    {
        return $this->cacheApp->get(
            'rspamd_history_' . $limit,
            function (ItemInterface $item) use ($limit): array {
                $item->expiresAfter(self::HISTORY_CACHE_TTL);

                try {
                    $data = $this->client->history($limit);

                    return $this->parseHistory($data);
                } catch (RspamdClientException) {
                    return [];
                }
            }
        );
    }

    /**
     * @return array<string, KpiValueDto>
     */
    private function fetchKpis(): array
    {
        try {
            $stat = $this->client->stat();

            return $this->parseStatToKpis($stat);
        } catch (RspamdClientException) {
            return $this->getEmptyKpis();
        }
    }

    private function fetchActionDistribution(): ActionDistributionDto
    {
        try {
            $pieData = $this->client->pie();

            if ([] !== $pieData) {
                return $this->parsePieData($pieData);
            }
        } catch (RspamdClientException) {
            // Return empty if /pie endpoint fails
        }

        return ActionDistributionDto::empty();
    }

    /**
     * @return array<string, KpiValueDto>
     */
    private function getEmptyKpis(): array
    {
        return $this->createKpisFromValues([]);
    }

    /**
     * @param array<string, mixed> $stat
     *
     * @return array<string, KpiValueDto>
     */
    private function parseStatToKpis(array $stat): array
    {
        $values = [];
        foreach (RspamdConstants::STAT_TO_KPI_MAP as $statKey => $kpiKey) {
            $values[$kpiKey] = $stat[$statKey] ?? null;
        }

        return $this->createKpisFromValues($values);
    }

    /**
     * @param array<string, int|float|null> $values
     *
     * @return array<string, KpiValueDto>
     */
    private function createKpisFromValues(array $values): array
    {
        $kpis = [];
        foreach (RspamdConstants::KPI_DEFINITIONS as $key => [$label, $icon]) {
            $kpis[$key] = new KpiValueDto($label, $values[$key] ?? null, null, $icon);
        }

        return $kpis;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseGraphData(array $data, string $type): TimeSeriesDto
    {
        if ($this->isNewGraphFormat($data)) {
            return $this->parseNewGraphFormat($data, $type);
        }

        if ($this->isLegacyGraphFormat($data)) {
            return $this->parseLegacyGraphFormat($data, $type);
        }

        return new TimeSeriesDto($type, [], []);
    }

    /**
     * Check if data is in new format: array of arrays with {x: timestamp, y: value} objects.
     *
     * @param array<string, mixed> $data
     */
    private function isNewGraphFormat(array $data): bool
    {
        return isset($data[0]) && \is_array($data[0]) && isset($data[0][0]) && \is_array($data[0][0]);
    }

    /**
     * Check if data is in legacy format: array of objects with timestamp and action counts.
     *
     * @param array<string, mixed> $data
     */
    private function isLegacyGraphFormat(array $data): bool
    {
        return isset($data[0]) && \is_array($data[0]);
    }

    /**
     * Parse new graph format: [[{x: timestamp, y: value}, ...], ...]
     *
     * @param array<string, mixed> $data
     */
    private function parseNewGraphFormat(array $data, string $type): TimeSeriesDto
    {
        $timestampKeys = $this->extractAndSampleTimestamps($data);
        $labels = array_map(fn (int $ts) => $this->formatTimestamp($ts, $type), $timestampKeys);
        $datasets = $this->extractSeriesData($data, $timestampKeys);

        return new TimeSeriesDto($type, $labels, $datasets);
    }

    /**
     * Parse legacy graph format: array of objects with timestamp and action counts.
     *
     * @param array<string, mixed> $data
     */
    private function parseLegacyGraphFormat(array $data, string $type): TimeSeriesDto
    {
        $labels = [];
        $datasets = [];

        foreach ($data as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $timestamp = $row['ts'] ?? $row['time'] ?? null;
            if (null === $timestamp) {
                continue;
            }

            $labels[] = $this->formatTimestamp((int) $timestamp, $type);

            foreach ($row as $key => $value) {
                if (\in_array($key, ['ts', 'time', 'timestamp'], true) || !\is_numeric($value)) {
                    continue;
                }

                $datasets[$key] ??= [];
                $datasets[$key][] = (float) $value;
            }
        }

        return new TimeSeriesDto($type, $labels, $datasets);
    }

    /**
     * Extract and sample timestamps from graph data.
     *
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    private function extractAndSampleTimestamps(array $data): array
    {
        $allTimestamps = [];

        foreach ($data as $series) {
            if (!\is_array($series)) {
                continue;
            }

            foreach ($series as $point) {
                if (\is_array($point) && isset($point['x']) && \is_numeric($point['x'])) {
                    $allTimestamps[(int) $point['x']] = true;
                }
            }
        }

        ksort($allTimestamps);
        $timestampKeys = array_keys($allTimestamps);

        return $this->sampleTimestamps($timestampKeys, 200);
    }

    /**
     * Sample timestamps if there are too many.
     *
     * @param list<int> $timestamps
     *
     * @return list<int>
     */
    private function sampleTimestamps(array $timestamps, int $maxLabels): array
    {
        if (\count($timestamps) <= $maxLabels) {
            return $timestamps;
        }

        $step = \count($timestamps) / $maxLabels;
        $sampled = [];
        for ($i = 0; $i < $maxLabels; ++$i) {
            $index = (int) ($i * $step);
            $sampled[] = $timestamps[$index];
        }

        return $sampled;
    }

    /**
     * Extract series data from graph format.
     *
     * @param array<string, mixed> $data
     * @param list<int>            $timestampKeys
     *
     * @return array<string, list<float>>
     */
    private function extractSeriesData(array $data, array $timestampKeys): array
    {
        $datasets = [];

        foreach ($data as $seriesIndex => $series) {
            if (!\is_array($series)) {
                continue;
            }

            $actionName = RspamdConstants::GRAPH_SERIES_ACTIONS[$seriesIndex] ?? 'series_' . $seriesIndex;
            $seriesData = $this->buildSeriesDataMap($series);

            foreach ($timestampKeys as $timestamp) {
                $datasets[$actionName][] = $seriesData[$timestamp] ?? 0.0;
            }
        }

        return $datasets;
    }

    /**
     * Build a map of timestamp => value for a series.
     *
     * @param array<int, mixed> $series
     *
     * @return array<int, float>
     */
    private function buildSeriesDataMap(array $series): array
    {
        $seriesData = [];

        foreach ($series as $point) {
            if (!\is_array($point) || !isset($point['x']) || !\is_numeric($point['x'])) {
                continue;
            }

            $timestamp = (int) $point['x'];
            $value = $point['y'] ?? null;
            $seriesData[$timestamp] = null === $value ? 0.0 : (float) $value;
        }

        return $seriesData;
    }

    /**
     * @param array<int|string, mixed> $pieData
     */
    private function parsePieData(array $pieData): ActionDistributionDto
    {
        $actions = [];
        $colors = [];

        // Parse array format: [{action: "...", value: ...}, ...]
        foreach ($pieData as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $action = $this->extractActionName($item);
            if (null === $action) {
                continue;
            }

            $actions[$action] = $this->extractActionValue($item);
            $color = $this->extractActionColor($item);
            if (null !== $color) {
                $colors[$action] = $color;
            }
        }

        // Handle key-value format: {action: count, ...}
        foreach ($pieData as $key => $value) {
            if (\is_string($key) && \is_numeric($value)) {
                $actions[$key] = (int) $value;
            }
        }

        return new ActionDistributionDto($actions, $colors);
    }

    /**
     * Extract action name from pie data item.
     *
     * @param array<string, mixed> $item
     */
    private function extractActionName(array $item): ?string
    {
        $action = $item['action'] ?? $item['label'] ?? $item['name'] ?? null;

        return \is_string($action) ? $action : null;
    }

    /**
     * Extract action value from pie data item.
     *
     * @param array<string, mixed> $item
     */
    private function extractActionValue(array $item): int
    {
        $value = $item['value'] ?? $item['data'] ?? $item['count'] ?? 0;

        return (int) $value;
    }

    /**
     * Extract action color from pie data item.
     *
     * @param array<string, mixed> $item
     */
    private function extractActionColor(array $item): ?string
    {
        $color = $item['color'] ?? null;

        return \is_string($color) ? $color : null;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<ActionThresholdDto>
     */
    private function parseActionsThresholds(array $data): array
    {
        $thresholds = [];

        foreach ($data as $item) {
            if (!\is_array($item) || !isset($item['action'], $item['value'])) {
                continue;
            }

            $thresholds[] = new ActionThresholdDto(
                (string) $item['action'],
                (float) $item['value']
            );
        }

        // Sort by threshold value descending
        usort($thresholds, static fn (ActionThresholdDto $a, ActionThresholdDto $b) => $b->threshold <=> $a->threshold);

        return $thresholds;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return list<SymbolCounterDto>
     */
    private function parseCounters(array $data, int $limit): array
    {
        $counters = [];

        foreach ($data as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $name = $item['name'] ?? $item['symbol'] ?? null;
            if (null === $name) {
                continue;
            }

            $counters[] = new SymbolCounterDto(
                (string) $name,
                (int) ($item['hits'] ?? $item['count'] ?? 0),
                (float) ($item['weight'] ?? 0),
                (float) ($item['frequency'] ?? 0),
                isset($item['time']) ? (float) $item['time'] : null,
                isset($item['description']) ? (string) $item['description'] : null
            );
        }

        // Sort by hits descending
        usort($counters, static fn (SymbolCounterDto $a, SymbolCounterDto $b) => $b->hits <=> $a->hits);

        return \array_slice($counters, 0, $limit);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<HistoryRowDto>
     */
    private function parseHistory(array $data): array
    {
        $rows = $data['rows'] ?? $data;

        if (!\is_array($rows)) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            try {
                $timestamp = $row['time'] ?? $row['unix_time'] ?? $row['ts'] ?? null;

                if (null === $timestamp) {
                    continue;
                }

                $time = $this->parseTimestamp($timestamp);
                $symbols = $this->parseSymbols($row);
                $recipient = $this->parseRecipient($row);

                $result[] = new HistoryRowDto(
                    $this->safeGetString($row, 'message-id', $this->safeGetString($row, 'id', uniqid('h_', true))),
                    $time,
                    $this->safeGetString($row, 'action', 'unknown'),
                    $this->safeGetNumeric($row, 'score'),
                    $this->safeGetNumeric($row, 'required_score'),
                    $this->safeGetString($row, 'sender_smtp', $this->safeGetString($row, 'sender')),
                    $recipient,
                    $this->safeGetString($row, 'ip'),
                    (int) $this->safeGetNumeric($row, 'size'),
                    $symbols,
                    isset($row['subject']) ? (string) $row['subject'] : null
                );
            } catch (\Exception $e) {
                // Skip malformed rows
                continue;
            }
        }

        return $result;
    }

    private function formatTimestamp(int $timestamp, string $type): string
    {
        $date = (new \DateTimeImmutable())->setTimestamp($timestamp);

        return match ($type) {
            TimeSeriesDto::TYPE_DAY => $date->format('H:i'),
            TimeSeriesDto::TYPE_WEEK => $date->format('D H:i'),
            TimeSeriesDto::TYPE_MONTH => $date->format('M d'),
            TimeSeriesDto::TYPE_YEAR => $date->format('M Y'),
            default => $date->format('Y-m-d H:i'),
        };
    }

    private function getCacheTtl(): int
    {
        return $this->cacheTtl > 0 ? $this->cacheTtl : self::DEFAULT_CACHE_TTL;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function safeGetArray(array $data, string $key, mixed $default = null): mixed
    {
        return $data[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function safeGetString(array $data, string $key, string $default = ''): string
    {
        $value = $this->safeGetArray($data, $key, $default);

        return \is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function safeGetNumeric(array $data, string $key, float $default = 0.0): float
    {
        $value = $this->safeGetArray($data, $key, $default);

        return \is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Parse timestamp from various formats.
     */
    private function parseTimestamp(mixed $timestamp): \DateTimeImmutable
    {
        return \is_numeric($timestamp)
            ? (new \DateTimeImmutable())->setTimestamp((int) $timestamp)
            : new \DateTimeImmutable((string) $timestamp);
    }

    /**
     * Parse symbols from history row.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function parseSymbols(array $row): array
    {
        if (!isset($row['symbols']) || !\is_array($row['symbols'])) {
            return [];
        }

        $symbols = [];
        foreach ($row['symbols'] as $key => $symbol) {
            if (\is_string($key)) {
                // Object format: key is symbol name
                $symbols[] = $key;
            } elseif (\is_string($symbol)) {
                // Array format: direct string values
                $symbols[] = $symbol;
            } elseif (\is_array($symbol) && isset($symbol['name'])) {
                // Array format: objects with 'name' property
                $symbols[] = (string) $symbol['name'];
            }
        }

        return $symbols;
    }

    /**
     * Parse recipient from history row.
     *
     * @param array<string, mixed> $row
     */
    private function parseRecipient(array $row): string
    {
        $recipient = $this->safeGetArray($row, 'rcpt_smtp', $this->safeGetArray($row, 'recipient', null));

        if (null === $recipient) {
            return '';
        }

        if (\is_array($recipient)) {
            return implode(', ', array_filter($recipient, 'is_string'));
        }

        return (string) $recipient;
    }
}
