<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Exception\Dovecot\DoveadmException;
use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Dovecot\DovecotStatsSampler;
use App\Service\Dovecot\DTO\StatsDumpDto;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AllowMockObjectsWithoutExpectations]
final class DovecotStatsSamplerTest extends TestCase
{
    private DoveadmHttpClient&MockObject $httpClient;
    private CacheInterface&MockObject $cache;
    private DovecotStatsSampler $sampler;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(DoveadmHttpClient::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->sampler = new DovecotStatsSampler(
            $this->httpClient,
            $this->cache,
            10, // sampleIntervalSeconds
            60, // snapshotTtlMinutes
        );
    }

    public function testGetSamplesReturnsEmptyWhenNoSamplesInCache(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('isConfigured')
            ->willReturn(true);

        $callCount = 0;
        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use (&$callCount) {
                ++$callCount;
                if (str_contains($key, 'last_sample_time')) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter');

                    return $callback($item);
                }

                if (str_contains($key, 'samples')) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter');

                    return $callback($item);
                }

                return null;
            });

        $sample = $this->createSample();
        $this->httpClient
            ->expects(self::once())
            ->method('statsDump')
            ->willReturn($sample);

        $this->cache
            ->method('delete')
            ->willReturn(true);

        $samples = $this->sampler->getSamples();

        self::assertIsArray($samples);
    }

    public function testGetSamplesDoesNotFetchWhenAllowFetchIsFalse(): void
    {
        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(self::callback(fn (string $key) => str_contains($key, 'samples')))
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter');

                return $callback($item);
            });

        $this->httpClient
            ->expects(self::never())
            ->method('statsDump');

        $samples = $this->sampler->getSamples(allowFetch: false);

        self::assertIsArray($samples);
    }

    public function testGetSamplesDoesNotFetchWhenNotConfigured(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('isConfigured')
            ->willReturn(false);

        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(self::callback(fn (string $key) => str_contains($key, 'samples')))
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter');

                return $callback($item);
            });

        $this->httpClient
            ->expects(self::never())
            ->method('statsDump');

        $samples = $this->sampler->getSamples();

        self::assertIsArray($samples);
    }

    public function testGetSamplesDoesNotFetchWhenIntervalNotPassed(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('isConfigured')
            ->willReturn(true);

        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                if ('dovecot_stats_last_sample' === $key) {
                    return new \DateTimeImmutable('-5 seconds'); // Less than 10 second interval
                }

                if ('dovecot_stats_samples' === $key) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter');

                    return $callback($item);
                }

                return null;
            });

        $this->httpClient
            ->expects(self::never())
            ->method('statsDump');

        $samples = $this->sampler->getSamples();

        self::assertIsArray($samples);
    }

    public function testGetLatestSampleReturnsNullWhenNoSamples(): void
    {
        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(self::callback(fn (string $key) => str_contains($key, 'samples')))
            ->willReturnCallback(function (string $key, callable $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter');

                return $callback($item);
            });

        $sample = $this->sampler->getLatestSample();

        self::assertNull($sample);
    }

    public function testGetLatestSampleReturnsLastSample(): void
    {
        $sample1 = $this->createSample('2024-01-01 10:00:00');
        $sample2 = $this->createSample('2024-01-01 10:01:00');

        $this->cache
            ->expects(self::once())
            ->method('get')
            ->with(self::callback(fn (string $key) => str_contains($key, 'samples')))
            ->willReturn([
                $sample1->toArray(),
                $sample2->toArray(),
            ]);

        $this->httpClient
            ->expects($this->never())
            ->method('statsDump');

        $latest = $this->sampler->getLatestSample();

        self::assertInstanceOf(StatsDumpDto::class, $latest);
        self::assertEquals($sample2->fetchedAt->getTimestamp(), $latest->fetchedAt->getTimestamp());
    }

    public function testForceFetchSample(): void
    {
        $sample = $this->createSample();

        $this->httpClient
            ->expects(self::once())
            ->method('statsDump')
            ->willReturn($sample);

        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                if (str_contains($key, 'samples')) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter');

                    return $callback($item);
                }

                if (str_contains($key, 'last_sample_time')) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter');

                    return $callback($item);
                }

                return null;
            });

        $this->cache
            ->method('delete')
            ->willReturn(true);

        $result = $this->sampler->forceFetchSample();

        self::assertInstanceOf(StatsDumpDto::class, $result);
        self::assertEquals($sample->fetchedAt->getTimestamp(), $result->fetchedAt->getTimestamp());
    }

    public function testForceFetchSampleThrowsOnException(): void
    {
        $this->httpClient
            ->expects(self::once())
            ->method('statsDump')
            ->willThrowException(new DoveadmException('Connection failed'));
        $this->cache
            ->expects($this->never())
            ->method('get');

        $this->expectException(DoveadmException::class);

        $this->sampler->forceFetchSample();
    }

    public function testGetLastSampleTimeReturnsNullWhenNotSet(): void
    {
        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) {
                if (str_contains($key, 'last_sample_time')) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter');

                    return $callback($item);
                }

                return null;
            });

        $time = $this->sampler->getLastSampleTime();

        self::assertNull($time);
    }

    public function testGetLastSampleTimeReturnsTimeWhenSet(): void
    {
        $expectedTime = new \DateTimeImmutable('2024-01-01 10:00:00');

        $this->httpClient
            ->expects($this->never())
            ->method('statsDump');

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($expectedTime) {
                if (str_contains($key, 'last_sample')) {
                    return $expectedTime;
                }

                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter');

                return $callback($item);
            });

        $time = $this->sampler->getLastSampleTime();

        self::assertInstanceOf(\DateTimeImmutable::class, $time);
        self::assertEquals($expectedTime->getTimestamp(), $time->getTimestamp());
    }

    public function testStoreSampleTrimsToMaxSamples(): void
    {
        // Create more than MAX_SAMPLES (360) samples
        $samples = [];
        for ($i = 0; $i < 400; ++$i) {
            $samples[] = $this->createSample('2024-01-01 10:00:00', offset: $i)->toArray();
        }

        $newSample = $this->createSample('2024-01-01 10:01:00');

        $this->cache
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($samples) {
                if (str_contains($key, 'samples')) {
                    return $samples;
                }

                if (str_contains($key, 'last_sample')) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->method('expiresAfter');

                    return $callback($item);
                }

                return null;
            });

        $this->httpClient
            ->expects($this->once())
            ->method('statsDump')
            ->willReturn($newSample);

        $this->cache
            ->expects($this->exactly(2))
            ->method('delete')
            ->willReturn(true);

        $result = $this->sampler->forceFetchSample();

        self::assertInstanceOf(StatsDumpDto::class, $result);
    }

    public function testStoreSampleRemovesOldSamples(): void
    {
        $oldSample = $this->createSample('2024-01-01 08:00:00'); // 2 hours ago
        $newSample = $this->createSample('2024-01-01 10:00:00');

        $this->cache
            ->expects($this->exactly(3))
            ->method('get')
            ->willReturnCallback(function (string $key, callable $callback) use ($oldSample) {
                if (str_contains($key, 'samples')) {
                    return [$oldSample->toArray()];
                }

                if (str_contains($key, 'last_sample')) {
                    $item = $this->createMock(ItemInterface::class);
                    $item->expects($this->once())->method('expiresAfter');

                    return $callback($item);
                }

                return null;
            });

        $this->httpClient
            ->expects($this->once())
            ->method('statsDump')
            ->willReturn($newSample);

        $this->cache
            ->method('delete')
            ->willReturn(true);

        $result = $this->sampler->forceFetchSample();

        self::assertInstanceOf(StatsDumpDto::class, $result);
    }

    private function createSample(string $time = '2024-01-01 10:00:00', ?int $resetTimestamp = null, int $offset = 0): StatsDumpDto
    {
        $fetchedAt = new \DateTimeImmutable($time)->modify("+{$offset} seconds");

        return new StatsDumpDto(
            fetchedAt: $fetchedAt,
            counters: [
                'num_logins' => 10 + $offset,
                'auth_successes' => 100 + $offset,
            ],
        );
    }
}
