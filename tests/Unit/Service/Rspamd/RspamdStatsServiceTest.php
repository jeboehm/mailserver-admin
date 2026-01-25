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
use App\Service\Rspamd\DTO\ActionThresholdDto;
use App\Service\Rspamd\DTO\HistoryRowDto;
use App\Service\Rspamd\DTO\RspamdSummaryDto;
use App\Service\Rspamd\DTO\SymbolCounterDto;
use App\Service\Rspamd\DTO\TimeSeriesDto;
use App\Service\Rspamd\RspamdControllerClient;
use App\Service\Rspamd\RspamdStatsService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

final class RspamdStatsServiceTest extends TestCase
{
    private CacheInterface $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    public function testGetSummaryReturnsSummaryWithHealthAndKpis(): void
    {
        $statData = [
            'scanned' => 100,
            'spam_count' => 5,
            'ham_count' => 95,
            'learned' => 2,
            'connections' => 50,
        ];

        $pieData = [
            ['action' => 'reject', 'value' => 5],
            ['action' => 'no action', 'value' => 95],
        ];

        // Create a client that returns pong for ping, stat data for stat, and pie data for pie
        $httpClient = new MockHttpClient([
            new MockResponse('pong', ['http_code' => 200]), // ping
            new MockResponse(json_encode($statData), ['http_code' => 200]), // stat
            new MockResponse(json_encode($pieData), ['http_code' => 200]), // pie
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $summary = $service->getSummary();

        self::assertInstanceOf(RspamdSummaryDto::class, $summary);
        self::assertTrue($summary->health->isOk());
        self::assertArrayHasKey('scanned', $summary->kpis);
        self::assertArrayHasKey('spam', $summary->kpis);
        self::assertArrayHasKey('ham', $summary->kpis);
        self::assertArrayHasKey('learned', $summary->kpis);
        self::assertArrayHasKey('connections', $summary->kpis);
    }

    public function testGetSummaryReturnsEmptyKpisWhenHealthNotOk(): void
    {
        // Create a client that returns an error for ping
        $httpClient = new MockHttpClient([
            new MockResponse('Error', ['http_code' => 500]), // ping fails
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $summary = $service->getSummary();

        self::assertTrue($summary->health->isCritical());
        self::assertInstanceOf(ActionDistributionDto::class, $summary->actionDistribution);
        self::assertTrue($summary->actionDistribution->isEmpty());
    }

    public function testGetHealthReturnsCachedHealth(): void
    {
        // Create a client that returns pong
        $httpClient = new MockHttpClient([
            new MockResponse('pong', ['http_code' => 200]), // ping - only called once due to cache
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $result1 = $service->getHealth();
        $result2 = $service->getHealth(); // Should be cached

        self::assertEquals($result1, $result2);
        self::assertTrue($result1->isOk());
    }

    public function testGetThroughputSeriesReturnsTimeSeries(): void
    {
        $graphData = [
            [
                ['x' => 1234567890, 'y' => 10],
                ['x' => 1234567900, 'y' => 20],
            ],
        ];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($graphData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $series = $service->getThroughputSeries('day');

        self::assertInstanceOf(TimeSeriesDto::class, $series);
        self::assertEquals('day', $series->type);
        self::assertNotEmpty($series->labels);
    }

    public function testGetThroughputSeriesReturnsEmptyOnInvalidType(): void
    {
        $httpClient = new MockHttpClient();
        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $series = $service->getThroughputSeries('invalid');

        self::assertInstanceOf(TimeSeriesDto::class, $series);
        self::assertEquals('invalid', $series->type);
        self::assertEmpty($series->labels);
        self::assertEmpty($series->datasets);
    }

    public function testGetThroughputSeriesReturnsEmptyOnException(): void
    {
        // Create a client that throws a connection error
        $httpClient = new MockHttpClient([
            static function () {
                throw new TransportException('Connection failed');
            },
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $series = $service->getThroughputSeries('day');

        self::assertInstanceOf(TimeSeriesDto::class, $series);
        self::assertTrue($series->isEmpty());
    }

    public function testGetActionDistributionReturnsDistribution(): void
    {
        $pieData = [
            ['action' => 'reject', 'value' => 10, 'color' => '#FF0000'],
            ['action' => 'no action', 'value' => 90],
        ];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($pieData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $distribution = $service->getActionDistribution();

        self::assertInstanceOf(ActionDistributionDto::class, $distribution);
        self::assertFalse($distribution->isEmpty());
        self::assertEquals(10, $distribution->actions['reject']);
        self::assertEquals(90, $distribution->actions['no action']);
    }

    public function testGetActionDistributionReturnsEmptyOnException(): void
    {
        $httpClient = new MockHttpClient([
            static function () {
                throw new TransportException('Connection failed');
            },
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $distribution = $service->getActionDistribution();

        self::assertInstanceOf(ActionDistributionDto::class, $distribution);
        self::assertTrue($distribution->isEmpty());
    }

    public function testGetActionThresholdsReturnsThresholds(): void
    {
        $actionsData = [
            ['action' => 'reject', 'value' => 15.0],
            ['action' => 'add header', 'value' => 6.0],
            ['action' => 'greylist', 'value' => 4.0],
        ];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($actionsData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $thresholds = $service->getActionThresholds();

        self::assertIsArray($thresholds);
        self::assertCount(3, $thresholds);
        self::assertInstanceOf(ActionThresholdDto::class, $thresholds[0]);
        self::assertEquals('reject', $thresholds[0]->action);
        self::assertEquals(15.0, $thresholds[0]->threshold);
    }

    public function testGetActionThresholdsReturnsEmptyOnException(): void
    {
        $httpClient = new MockHttpClient([
            static function () {
                throw new TransportException('Connection failed');
            },
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $thresholds = $service->getActionThresholds();

        self::assertIsArray($thresholds);
        self::assertEmpty($thresholds);
    }

    public function testGetTopSymbolsReturnsCounters(): void
    {
        $countersData = [
            ['symbol' => 'SYMBOL1', 'hits' => 100, 'weight' => 5.0, 'frequency' => 0.5],
            ['symbol' => 'SYMBOL2', 'hits' => 50, 'weight' => 3.0, 'frequency' => 0.3],
        ];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($countersData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $counters = $service->getTopSymbols(10);

        self::assertIsArray($counters);
        self::assertCount(2, $counters);
        self::assertInstanceOf(SymbolCounterDto::class, $counters[0]);
        self::assertEquals('SYMBOL1', $counters[0]->name);
        self::assertEquals(100, $counters[0]->hits);
    }

    public function testGetTopSymbolsRespectsLimit(): void
    {
        $countersData = [];
        for ($i = 0; $i < 50; ++$i) {
            $countersData[] = ['symbol' => "SYMBOL{$i}", 'hits' => 100 - $i, 'weight' => 0, 'frequency' => 0];
        }

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($countersData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $counters = $service->getTopSymbols(10);

        self::assertCount(10, $counters);
    }

    public function testGetTopSymbolsReturnsEmptyOnException(): void
    {
        $httpClient = new MockHttpClient([
            static function () {
                throw new TransportException('Connection failed');
            },
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $counters = $service->getTopSymbols(10);

        self::assertIsArray($counters);
        self::assertEmpty($counters);
    }

    public function testGetHistoryReturnsHistoryRows(): void
    {
        $historyData = [
            'rows' => [
                [
                    'time' => 1234567890,
                    'action' => 'reject',
                    'score' => 15.5,
                    'required_score' => 15.0,
                    'sender' => 'test@example.com',
                    'rcpt_smtp' => 'recipient@example.com',
                    'ip' => '192.168.1.1',
                    'size' => 1024,
                    'symbols' => ['SYMBOL1', 'SYMBOL2'],
                ],
            ],
        ];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($historyData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $history = $service->getHistory(50);

        self::assertIsArray($history);
        self::assertCount(1, $history);
        self::assertInstanceOf(HistoryRowDto::class, $history[0]);
        self::assertEquals('reject', $history[0]->action);
        self::assertEquals(15.5, $history[0]->score);
    }

    public function testGetHistoryReturnsEmptyOnException(): void
    {
        $httpClient = new MockHttpClient([
            static function () {
                throw new TransportException('Connection failed');
            },
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $history = $service->getHistory(50);

        self::assertIsArray($history);
        self::assertEmpty($history);
    }

    public function testGetSummaryUsesCache(): void
    {
        $statData = ['scanned' => 100, 'spam_count' => 5, 'ham_count' => 95, 'learned' => 2, 'connections' => 50];
        $pieData = [['action' => 'reject', 'value' => 5]];

        // Only one set of responses needed due to caching
        $httpClient = new MockHttpClient([
            new MockResponse('pong', ['http_code' => 200]), // ping
            new MockResponse(json_encode($statData), ['http_code' => 200]), // stat
            new MockResponse(json_encode($pieData), ['http_code' => 200]), // pie
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $summary1 = $service->getSummary();
        $summary2 = $service->getSummary(); // Should use cache

        self::assertEquals($summary1, $summary2);
    }

    public function testParseLegacyGraphFormat(): void
    {
        $legacyGraphData = [
            ['ts' => 1234567890, 'reject' => 5, 'no action' => 10],
            ['ts' => 1234567900, 'reject' => 3, 'no action' => 12],
        ];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($legacyGraphData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient($httpClient, 'http://localhost:11334', 2500, 'test-password');
        $service = $this->createServiceWithClient($client);
        $series = $service->getThroughputSeries('day');

        self::assertInstanceOf(TimeSeriesDto::class, $series);
        self::assertNotEmpty($series->labels);
        self::assertArrayHasKey('reject', $series->datasets);
        self::assertArrayHasKey('no action', $series->datasets);
    }

    private function createServiceWithClient(RspamdControllerClient $client, int $cacheTtl = 10): RspamdStatsService
    {
        return new RspamdStatsService($client, $this->cache, $cacheTtl);
    }
}
