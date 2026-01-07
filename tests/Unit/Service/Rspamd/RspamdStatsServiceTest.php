<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd;

use App\Service\Rspamd\DTO\ActionDistributionDto;
use App\Service\Rspamd\DTO\HealthDto;
use App\Service\Rspamd\DTO\KpiValueDto;
use App\Service\Rspamd\RspamdClientException;
use App\Service\Rspamd\RspamdControllerClient;
use App\Service\Rspamd\RspamdMetricsParser;
use App\Service\Rspamd\RspamdStatsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class RspamdStatsServiceTest extends TestCase
{
    private MockObject|RspamdControllerClient $client;
    private MockObject|RspamdMetricsParser $parser;
    private MockObject|CacheInterface $cache;
    private RspamdStatsService $service;

    protected function setUp(): void
    {
        $this->client = $this->createMock(RspamdControllerClient::class);
        $this->parser = $this->createMock(RspamdMetricsParser::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->service = new RspamdStatsService(
            $this->client,
            $this->parser,
            $this->cache,
            10
        );
    }

    public function testGetSummarySuccess(): void
    {
        $healthDto = HealthDto::ok('All good');
        $kpis = [
            'scanned' => new KpiValueDto('Scanned', 1000),
        ];

        $this->client
            ->expects($this->once())
            ->method('ping')
            ->willReturn($healthDto);

        $this->client
            ->expects($this->once())
            ->method('metrics')
            ->willReturn('rspamd_scanned_total 1000');

        $this->parser
            ->expects($this->once())
            ->method('extractKpis')
            ->willReturn($kpis);

        $this->client
            ->expects($this->once())
            ->method('pie')
            ->willReturn([['action' => 'no action', 'value' => 1000]]);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('rspamd_summary', $this->anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter')->with(10);

                return $callback($item);
            });

        $summary = $this->service->getSummary();

        self::assertTrue($summary->health->isOk());
        self::assertArrayHasKey('scanned', $summary->kpis);
        self::assertFalse($summary->actionDistribution->isEmpty());
    }

    public function testGetSummaryWhenRspamdDown(): void
    {
        $healthDto = HealthDto::critical('Connection failed');

        $this->client
            ->expects($this->once())
            ->method('ping')
            ->willReturn($healthDto);

        $this->client
            ->expects($this->never())
            ->method('metrics');

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $summary = $this->service->getSummary();

        self::assertTrue($summary->health->isCritical());
        self::assertSame('Connection failed', $summary->health->message);
    }

    public function testGetHealth(): void
    {
        $healthDto = HealthDto::ok('Healthy');

        $this->client
            ->expects($this->once())
            ->method('ping')
            ->willReturn($healthDto);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('rspamd_health', $this->anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $health = $this->service->getHealth();

        self::assertTrue($health->isOk());
    }

    public function testGetThroughputSeriesValidType(): void
    {
        // New format: array of arrays with {x: timestamp, y: value} objects
        $graphData = [
            [
                ['x' => 1609459200, 'y' => 10],
                ['x' => 1609462800, 'y' => 20],
            ],
            [
                ['x' => 1609459200, 'y' => 100],
                ['x' => 1609462800, 'y' => 200],
            ],
        ];

        $this->client
            ->expects($this->once())
            ->method('graph')
            ->with('day')
            ->willReturn($graphData);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('rspamd_throughput_day', $this->anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $series = $this->service->getThroughputSeries('day');

        self::assertSame('day', $series->type);
        self::assertFalse($series->isEmpty());
        self::assertArrayHasKey('reject', $series->datasets);
        self::assertArrayHasKey('soft reject', $series->datasets);
    }

    public function testGetThroughputSeriesInvalidType(): void
    {
        $series = $this->service->getThroughputSeries('invalid');

        self::assertSame('invalid', $series->type);
        self::assertTrue($series->isEmpty());
    }

    public function testGetActionDistribution(): void
    {
        $pieData = [
            ['action' => 'reject', 'value' => 100],
            ['action' => 'no action', 'value' => 5000],
        ];

        $this->client
            ->expects($this->once())
            ->method('pie')
            ->willReturn($pieData);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('rspamd_action_distribution', $this->anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $distribution = $this->service->getActionDistribution();

        self::assertFalse($distribution->isEmpty());
    }

    public function testGetActionDistributionFallbackToMetrics(): void
    {
        $this->client
            ->expects($this->once())
            ->method('pie')
            ->willThrowException(RspamdClientException::connectionFailed('test'));

        $this->client
            ->expects($this->once())
            ->method('metrics')
            ->willReturn('rspamd_actions_total{action="reject"} 100');

        $this->parser
            ->expects($this->once())
            ->method('extractActionDistribution')
            ->willReturn(new ActionDistributionDto(['reject' => 100]));

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $distribution = $this->service->getActionDistribution();

        self::assertFalse($distribution->isEmpty());
        self::assertSame(100, $distribution->actions['reject']);
    }

    public function testGetActionThresholds(): void
    {
        $actionsData = [
            ['action' => 'reject', 'value' => 15.0],
            ['action' => 'add header', 'value' => 6.0],
        ];

        $this->client
            ->expects($this->once())
            ->method('actions')
            ->willReturn($actionsData);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('rspamd_action_thresholds', $this->anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $thresholds = $this->service->getActionThresholds();

        self::assertCount(2, $thresholds);
        // Should be sorted by threshold descending
        self::assertSame('reject', $thresholds[0]->action);
        self::assertSame(15.0, $thresholds[0]->threshold);
    }

    public function testGetTopSymbols(): void
    {
        $countersData = [
            ['name' => 'SYMBOL_A', 'hits' => 1000, 'weight' => 5.0, 'frequency' => 0.8],
            ['name' => 'SYMBOL_B', 'hits' => 500, 'weight' => -1.0, 'frequency' => 0.4],
        ];

        $this->client
            ->expects($this->once())
            ->method('counters')
            ->willReturn($countersData);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('rspamd_top_symbols_20', $this->anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $symbols = $this->service->getTopSymbols(20);

        self::assertCount(2, $symbols);
        // Should be sorted by hits descending
        self::assertSame('SYMBOL_A', $symbols[0]->name);
        self::assertSame(1000, $symbols[0]->hits);
    }

    public function testGetHistory(): void
    {
        $historyData = [
            'rows' => [
                [
                    'time' => 1609459200,
                    'action' => 'no action',
                    'score' => 1.5,
                    'required_score' => 15.0,
                    'sender_smtp' => 'sender@example.com',
                    'rcpt_smtp' => 'recipient@example.com',
                    'ip' => '192.168.1.1',
                    'size' => 1024,
                    'symbols' => ['SYMBOL_A'],
                ],
            ],
        ];

        $this->client
            ->expects($this->once())
            ->method('history')
            ->with(50)
            ->willReturn($historyData);

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with('rspamd_history_50', $this->anything())
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');

                return $callback($item);
            });

        $history = $this->service->getHistory(50);

        self::assertCount(1, $history);
        self::assertSame('no action', $history[0]->action);
        self::assertSame(1.5, $history[0]->score);
    }
}
