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
        private RspamdMetricsParser $metricsParser,
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
                $item->expiresAfter($this->cacheTtl > 0 ? $this->cacheTtl : self::DEFAULT_CACHE_TTL);

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
                $item->expiresAfter($this->cacheTtl > 0 ? $this->cacheTtl : self::DEFAULT_CACHE_TTL);

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
                } catch (RspamdClientException) {
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
                $item->expiresAfter($this->cacheTtl > 0 ? $this->cacheTtl : self::DEFAULT_CACHE_TTL);

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
                $item->expiresAfter($this->cacheTtl > 0 ? $this->cacheTtl : self::DEFAULT_CACHE_TTL);

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
                $item->expiresAfter($this->cacheTtl > 0 ? $this->cacheTtl : self::DEFAULT_CACHE_TTL);

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
            $metricsText = $this->client->metrics();

            return $this->metricsParser->extractKpis($metricsText);
        } catch (RspamdClientException) {
            // Try fallback to /stat endpoint
            try {
                $stat = $this->client->stat();

                return $this->parseStatToKpis($stat);
            } catch (RspamdClientException) {
                return $this->getEmptyKpis();
            }
        }
    }

    private function fetchActionDistribution(): ActionDistributionDto
    {
        // Try /pie endpoint first
        try {
            $pieData = $this->client->pie();

            if ([] !== $pieData) {
                return $this->parsePieData($pieData);
            }
        } catch (RspamdClientException) {
            // Fall through to metrics fallback
        }

        // Fallback to metrics
        try {
            $metricsText = $this->client->metrics();

            return $this->metricsParser->extractActionDistribution($metricsText);
        } catch (RspamdClientException) {
            return ActionDistributionDto::empty();
        }
    }

    /**
     * @return array<string, KpiValueDto>
     */
    private function getEmptyKpis(): array
    {
        return [
            'scanned' => new KpiValueDto('Messages scanned', null, null, 'fa-envelope'),
            'spam' => new KpiValueDto('Spam detected', null, null, 'fa-ban'),
            'ham' => new KpiValueDto('Ham (clean)', null, null, 'fa-check'),
            'learned' => new KpiValueDto('Learned', null, null, 'fa-graduation-cap'),
            'connections' => new KpiValueDto('Connections', null, null, 'fa-plug'),
        ];
    }

    /**
     * @param array<string, mixed> $stat
     *
     * @return array<string, KpiValueDto>
     */
    private function parseStatToKpis(array $stat): array
    {
        return [
            'scanned' => new KpiValueDto(
                'Messages scanned',
                $stat['scanned'] ?? null,
                null,
                'fa-envelope'
            ),
            'spam' => new KpiValueDto(
                'Spam detected',
                $stat['spam_count'] ?? null,
                null,
                'fa-ban'
            ),
            'ham' => new KpiValueDto(
                'Ham (clean)',
                $stat['ham_count'] ?? null,
                null,
                'fa-check'
            ),
            'learned' => new KpiValueDto(
                'Learned',
                $stat['learned'] ?? null,
                null,
                'fa-graduation-cap'
            ),
            'connections' => new KpiValueDto(
                'Connections',
                $stat['connections'] ?? null,
                null,
                'fa-plug'
            ),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parseGraphData(array $data, string $type): TimeSeriesDto
    {
        $labels = [];
        $datasets = [];

        // Rspamd graph data structure varies by version
        // Common format: array of objects with timestamp and action counts
        if (isset($data[0]) && \is_array($data[0])) {
            foreach ($data as $row) {
                if (!isset($row['ts']) && !isset($row['time'])) {
                    continue;
                }

                $timestamp = $row['ts'] ?? $row['time'] ?? 0;
                $labels[] = $this->formatTimestamp((int) $timestamp, $type);

                foreach ($row as $key => $value) {
                    if (\in_array($key, ['ts', 'time', 'timestamp'], true)) {
                        continue;
                    }

                    if (\is_numeric($value)) {
                        $datasets[$key] ??= [];
                        $datasets[$key][] = (float) $value;
                    }
                }
            }
        }

        return new TimeSeriesDto($type, $labels, $datasets);
    }

    /**
     * @param array<int|string, mixed> $pieData
     */
    private function parsePieData(array $pieData): ActionDistributionDto
    {
        $actions = [];

        foreach ($pieData as $item) {
            if (\is_array($item)) {
                $action = $item['action'] ?? $item['name'] ?? null;
                $value = $item['value'] ?? $item['count'] ?? 0;

                if (null !== $action && \is_string($action)) {
                    $actions[$action] = (int) $value;
                }
            } elseif (\is_string($item)) {
                // Handle format where key is action name and value is count
                continue;
            }
        }

        // Handle key-value format
        foreach ($pieData as $key => $value) {
            if (\is_string($key) && \is_numeric($value)) {
                $actions[$key] = (int) $value;
            }
        }

        return new ActionDistributionDto($actions);
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
            if (\is_array($item) && isset($item['action'], $item['value'])) {
                $thresholds[] = new ActionThresholdDto(
                    (string) $item['action'],
                    (float) $item['value']
                );
            }
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

                $time = \is_numeric($timestamp)
                    ? (new \DateTimeImmutable())->setTimestamp((int) $timestamp)
                    : new \DateTimeImmutable((string) $timestamp);

                $symbols = [];
                if (isset($row['symbols']) && \is_array($row['symbols'])) {
                    foreach ($row['symbols'] as $symbol) {
                        if (\is_string($symbol)) {
                            $symbols[] = $symbol;
                        } elseif (\is_array($symbol) && isset($symbol['name'])) {
                            $symbols[] = (string) $symbol['name'];
                        }
                    }
                }

                $result[] = new HistoryRowDto(
                    (string) ($row['message-id'] ?? $row['id'] ?? uniqid('h_', true)),
                    $time,
                    (string) ($row['action'] ?? 'unknown'),
                    (float) ($row['score'] ?? 0),
                    (float) ($row['required_score'] ?? 0),
                    (string) ($row['sender_smtp'] ?? $row['sender'] ?? ''),
                    (string) ($row['rcpt_smtp'] ?? $row['recipient'] ?? ''),
                    (string) ($row['ip'] ?? ''),
                    (int) ($row['size'] ?? 0),
                    $symbols,
                    isset($row['subject']) ? (string) $row['subject'] : null
                );
            } catch (\Exception) {
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
            TimeSeriesDto::TYPE_HOURLY => $date->format('H:i'),
            TimeSeriesDto::TYPE_DAILY => $date->format('D H:00'),
            TimeSeriesDto::TYPE_WEEKLY => $date->format('D'),
            TimeSeriesDto::TYPE_MONTHLY => $date->format('M d'),
            default => $date->format('Y-m-d H:i'),
        };
    }
}
