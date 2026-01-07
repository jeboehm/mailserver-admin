<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot;

use App\Exception\Dovecot\DoveadmException;
use App\Service\Dovecot\DTO\StatsDumpDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Manages sampling of Dovecot statistics.
 *
 * This service maintains a rolling buffer of stats samples in cache,
 * respecting the configured sample interval to avoid overwhelming Dovecot.
 */
readonly class DovecotStatsSampler
{
    private const string CACHE_KEY_SAMPLES = 'dovecot_stats_samples';
    private const string CACHE_KEY_LAST_SAMPLE_TIME = 'dovecot_stats_last_sample';
    private const int DEFAULT_SAMPLE_INTERVAL_SECONDS = 10;
    private const int DEFAULT_SNAPSHOT_TTL_MINUTES = 60;
    private const int MAX_SAMPLES = 360; // At 10s intervals, ~1 hour of data

    public function __construct(
        private DoveadmHttpClient $httpClient,
        private CacheInterface $cacheApp,
        #[Autowire('%env(default::int:DOVECOT_STATS_SAMPLE_INTERVAL_SECONDS)%')]
        private ?int $sampleIntervalSeconds,
        #[Autowire('%env(default::int:DOVECOT_STATS_SNAPSHOT_TTL)%')]
        private ?int $snapshotTtlMinutes,
    ) {
    }

    /**
     * Get all available samples, optionally fetching a new one if needed.
     *
     * @param bool $allowFetch If true, will fetch a new sample if the interval has passed
     *
     * @return list<StatsDumpDto>
     */
    public function getSamples(bool $allowFetch = true): array
    {
        if ($allowFetch && $this->shouldFetchNewSample()) {
            $this->fetchAndStoreSample();
        }

        return $this->loadSamples();
    }

    /**
     * Get the most recent sample, or null if none available.
     */
    public function getLatestSample(): ?StatsDumpDto
    {
        $samples = $this->getSamples();

        return empty($samples) ? null : $samples[count($samples) - 1];
    }

    /**
     * Force fetch a new sample regardless of the interval.
     *
     * @throws DoveadmException
     */
    public function forceFetchSample(): StatsDumpDto
    {
        return $this->fetchAndStoreSample();
    }

    /**
     * Get the time of the last sample, or null if none.
     */
    public function getLastSampleTime(): ?\DateTimeImmutable
    {
        return $this->cacheApp->get(
            self::CACHE_KEY_LAST_SAMPLE_TIME,
            function (ItemInterface $item): ?\DateTimeImmutable {
                $item->expiresAfter(new \DateInterval('PT10S')); // Trigger refetch

                return new \DateTimeImmutable();
            }
        );
    }

    /**
     * Check if we should fetch a new sample based on the interval.
     */
    private function shouldFetchNewSample(): bool
    {
        if (!$this->httpClient->isConfigured()) {
            return false;
        }

        $lastSampleTime = $this->getLastSampleTime();

        if (null === $lastSampleTime) {
            return true;
        }

        $interval = $this->sampleIntervalSeconds ?? self::DEFAULT_SAMPLE_INTERVAL_SECONDS;
        $nextSampleTime = $lastSampleTime->modify('+' . $interval . ' seconds');

        return new \DateTimeImmutable() >= $nextSampleTime;
    }

    /**
     * Fetch a new sample from Dovecot and store it.
     *
     * @throws DoveadmException
     */
    private function fetchAndStoreSample(): StatsDumpDto
    {
        $sample = $this->httpClient->statsDump();

        $this->storeSample($sample);

        return $sample;
    }

    /**
     * Store a sample in the cache, maintaining the ring buffer.
     */
    private function storeSample(StatsDumpDto $sample): void
    {
        $samples = $this->loadSamples();

        // Add new sample
        $samples[] = $sample;

        // Trim to max size
        if (count($samples) > self::MAX_SAMPLES) {
            $samples = array_slice($samples, -self::MAX_SAMPLES);
        }

        // Remove samples older than TTL
        $ttlMinutes = $this->snapshotTtlMinutes ?? self::DEFAULT_SNAPSHOT_TTL_MINUTES;
        $cutoff = (new \DateTimeImmutable())->modify('-' . $ttlMinutes . ' minutes');

        $samples = array_values(array_filter(
            $samples,
            static fn (StatsDumpDto $s) => $s->fetchedAt >= $cutoff
        ));

        $this->saveSamples($samples);
        $this->updateLastSampleTime($sample->fetchedAt);
    }

    /**
     * Load samples from cache.
     *
     * @return list<StatsDumpDto>
     */
    private function loadSamples(): array
    {
        $ttlMinutes = $this->snapshotTtlMinutes ?? self::DEFAULT_SNAPSHOT_TTL_MINUTES;

        $serialized = $this->cacheApp->get(
            self::CACHE_KEY_SAMPLES,
            function (ItemInterface $item) use ($ttlMinutes): array {
                $item->expiresAfter(new \DateInterval('PT' . ($ttlMinutes + 10) . 'M'));

                return [];
            }
        );

        // Deserialize arrays back to DTOs
        return array_map(
            static fn (array $data) => StatsDumpDto::fromArray($data),
            $serialized
        );
    }

    /**
     * Save samples to cache.
     *
     * @param list<StatsDumpDto> $samples
     */
    private function saveSamples(array $samples): void
    {
        $ttlMinutes = $this->snapshotTtlMinutes ?? self::DEFAULT_SNAPSHOT_TTL_MINUTES;

        // Serialize samples for storage
        $serialized = array_map(
            static fn (StatsDumpDto $s) => $s->toArray(),
            $samples
        );

        // Use a callback that returns the data to store
        $this->cacheApp->delete(self::CACHE_KEY_SAMPLES);
        $this->cacheApp->get(
            self::CACHE_KEY_SAMPLES,
            function (ItemInterface $item) use ($serialized, $ttlMinutes): array {
                $item->expiresAfter(new \DateInterval('PT' . ($ttlMinutes + 10) . 'M'));

                return $serialized;
            }
        );
    }

    /**
     * Update the last sample time in cache.
     */
    private function updateLastSampleTime(\DateTimeImmutable $time): void
    {
        $ttlMinutes = $this->snapshotTtlMinutes ?? self::DEFAULT_SNAPSHOT_TTL_MINUTES;

        $this->cacheApp->delete(self::CACHE_KEY_LAST_SAMPLE_TIME);
        $this->cacheApp->get(
            self::CACHE_KEY_LAST_SAMPLE_TIME,
            function (ItemInterface $item) use ($time, $ttlMinutes): \DateTimeImmutable {
                $item->expiresAfter(new \DateInterval('PT' . ($ttlMinutes + 10) . 'M'));

                return $time;
            }
        );
    }
}
