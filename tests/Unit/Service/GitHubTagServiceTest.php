<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service;

use App\Service\GitHubTagService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GitHubTagServiceTest extends TestCase
{
    private MockObject|HttpClientInterface $httpClient;
    private MockObject|CacheInterface $cache;
    private GitHubTagService $service;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->service = new GitHubTagService($this->httpClient, $this->cache);
    }

    public function testGetLatestTagSuccessWithVPrefix(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['name' => 'v1.2.3'],
                ['name' => 'v1.2.2'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://api.github.com/repos/jeboehm/mailserver-admin/tags',
                $this->callback(function ($options) {
                    return isset($options['headers']['Accept'])
                        && 'application/vnd.github.v3+json' === $options['headers']['Accept']
                        && isset($options['timeout'])
                        && 5 === $options['timeout'];
                })
            )
            ->willReturn($response);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with($this->callback(function ($interval) {
                return $interval instanceof \DateInterval && 8 === $interval->h;
            }))
            ->willReturnSelf();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo(\md5(GitHubTagService::class . 'jeboehmmailserver-admin')))
            ->willReturnCallback(function ($key, $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetLatestTagSuccessWithoutVPrefix(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['name' => '1.2.3'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->willReturnSelf();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetLatestTagEmptyTagsArray(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->willReturnSelf();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTagMissingNameKey(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['other' => 'value'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->willReturnSelf();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTagNon200StatusCode(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(500);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->willReturnSelf();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTagExceptionHandling(): void
    {
        $exception = new \RuntimeException('Network error');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willThrowException($exception);

        $cacheItem = $this->createMock(ItemInterface::class);
        $callCount = 0;
        $cacheItem->expects($this->exactly(2))
            ->method('expiresAfter')
            ->willReturnCallback(function ($interval) use (&$callCount, $cacheItem) {
                ++$callCount;
                if (1 === $callCount) {
                    $this->assertInstanceOf(\DateInterval::class, $interval);
                    $this->assertEquals(8, $interval->h);
                } else {
                    $this->assertInstanceOf(\DateInterval::class, $interval);
                    $this->assertEquals(2, $interval->h);
                }

                return $cacheItem;
            });

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo(\md5(GitHubTagService::class . 'jeboehmmailserver-admin')))
            ->willReturnCallback(function ($key, $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertNull($result);
    }

    public function testGetLatestTagFromPathSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $response->expects($this->once())
            ->method('toArray')
            ->willReturn([
                ['name' => 'v1.2.3'],
            ]);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $cacheItem = $this->createMock(ItemInterface::class);
        $cacheItem->expects($this->once())
            ->method('expiresAfter')
            ->willReturnSelf();

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($cacheItem) {
                return $callback($cacheItem);
            });

        $result = $this->service->getLatestTagFromPath('jeboehm/mailserver-admin');

        $this->assertEquals('1.2.3', $result);
    }

    public function testGetLatestTagFromPathInvalidFormat(): void
    {
        $result = $this->service->getLatestTagFromPath('invalid');

        $this->assertNull($result);
    }

    public function testGetLatestTagCacheHit(): void
    {
        $cachedData = [
            ['name' => 'v2.0.0'],
        ];

        $this->cache
            ->expects($this->once())
            ->method('get')
            ->with($this->equalTo(\md5(GitHubTagService::class . 'jeboehmmailserver-admin')))
            ->willReturn($cachedData);

        $this->httpClient
            ->expects($this->never())
            ->method('request');

        $result = $this->service->getLatestTag('jeboehm', 'mailserver-admin');

        $this->assertEquals('2.0.0', $result);
    }
}
