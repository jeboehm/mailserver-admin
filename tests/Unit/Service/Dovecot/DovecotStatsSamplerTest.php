<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Dovecot\DovecotStatsSampler;
use App\Service\Dovecot\DTO\StatsDumpDto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class DovecotStatsSamplerTest extends TestCase
{
    public function testGetSamplesReturnsEmptyArrayWhenNoSamples(): void
    {
        $httpClient = $this->createMock(DoveadmHttpClient::class);
        $httpClient->method('isConfigured')->willReturn(false);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);

                return $callback($item);
            });

        $sampler = new DovecotStatsSampler(
            httpClient: $httpClient,
            cacheApp: $cache,
            logger: null,
            sampleIntervalSeconds: 10,
            snapshotTtlMinutes: 60,
        );

        $samples = $sampler->getSamples(allowFetch: false);

        self::assertEmpty($samples);
    }

    public function testGetLatestSampleReturnsNullWhenNoSamples(): void
    {
        $httpClient = $this->createMock(DoveadmHttpClient::class);
        $httpClient->method('isConfigured')->willReturn(false);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);

                return $callback($item);
            });

        $sampler = new DovecotStatsSampler(
            httpClient: $httpClient,
            cacheApp: $cache,
            logger: null,
            sampleIntervalSeconds: 10,
            snapshotTtlMinutes: 60,
        );

        self::assertNull($sampler->getLatestSample());
    }

    public function testShouldNotFetchWhenNotConfigured(): void
    {
        $httpClient = $this->createMock(DoveadmHttpClient::class);
        $httpClient->method('isConfigured')->willReturn(false);
        $httpClient->expects(self::never())->method('statsDump');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);

                return $callback($item);
            });

        $sampler = new DovecotStatsSampler(
            httpClient: $httpClient,
            cacheApp: $cache,
            logger: null,
            sampleIntervalSeconds: 10,
            snapshotTtlMinutes: 60,
        );

        $sampler->getSamples(allowFetch: true);
    }

    public function testForceFetchSampleFetchesAndStores(): void
    {
        $expectedSample = new StatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: ['num_logins' => 42],
        );

        $httpClient = $this->createMock(DoveadmHttpClient::class);
        $httpClient->method('isConfigured')->willReturn(true);
        $httpClient->expects(self::once())
            ->method('statsDump')
            ->willReturn($expectedSample);

        $cachedData = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$cachedData) {
                if (isset($cachedData[$key])) {
                    return $cachedData[$key];
                }
                $item = $this->createMock(ItemInterface::class);

                return $callback($item);
            });
        $cache->method('delete')
            ->willReturnCallback(function (string $key) use (&$cachedData) {
                unset($cachedData[$key]);

                return true;
            });

        $sampler = new DovecotStatsSampler(
            httpClient: $httpClient,
            cacheApp: $cache,
            logger: null,
            sampleIntervalSeconds: 10,
            snapshotTtlMinutes: 60,
        );

        $result = $sampler->forceFetchSample();

        self::assertSame($expectedSample, $result);
    }

    public function testResetDetectionLogsAndClearsSamples(): void
    {
        $existingSample = new StatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable('-1 minute'),
            lastUpdateSeconds: null,
            resetTimestamp: 1000,
            counters: ['num_logins' => 100],
        );

        $newSampleWithReset = new StatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: 2000, // Different reset timestamp
            counters: ['num_logins' => 10],
        );

        $httpClient = $this->createMock(DoveadmHttpClient::class);
        $httpClient->method('isConfigured')->willReturn(true);
        $httpClient->method('statsDump')->willReturn($newSampleWithReset);

        $cachedSamples = [$existingSample->toArray()];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$cachedSamples) {
                if (str_contains($key, 'samples') && !empty($cachedSamples)) {
                    return $cachedSamples;
                }
                $item = $this->createMock(ItemInterface::class);

                return $callback($item);
            });
        $cache->method('delete')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('reset detected'));

        $sampler = new DovecotStatsSampler(
            httpClient: $httpClient,
            cacheApp: $cache,
            logger: $logger,
            sampleIntervalSeconds: 10,
            snapshotTtlMinutes: 60,
        );

        $sampler->forceFetchSample();
    }
}
