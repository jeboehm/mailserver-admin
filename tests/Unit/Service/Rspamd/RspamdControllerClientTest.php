<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Rspamd;

use App\Service\Rspamd\DTO\HealthDto;
use App\Service\Rspamd\RspamdClientException;
use App\Service\Rspamd\RspamdControllerClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class RspamdControllerClientTest extends TestCase
{
    private MockObject|HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testPingSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('pong');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'http://rspamd:11334/ping', $this->anything())
            ->willReturn($response);

        $client = new RspamdControllerClient(
            $this->httpClient,
            'http://rspamd:11334',
            'secret',
            2500
        );

        $health = $client->ping();

        self::assertTrue($health->isOk());
        self::assertSame('Rspamd is healthy', $health->message);
        self::assertSame(200, $health->httpStatus);
        self::assertNotNull($health->latencyMs);
    }

    public function testPingWithNoUrl(): void
    {
        $client = new RspamdControllerClient(
            $this->httpClient,
            '',
            '',
            2500
        );

        $health = $client->ping();

        self::assertTrue($health->isCritical());
        self::assertSame('Rspamd controller URL not configured', $health->message);
    }

    public function testMetricsSuccess(): void
    {
        $metricsContent = "rspamd_scanned_total 12345\n";
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn($metricsContent);

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'http://rspamd:11334/metrics', $this->anything())
            ->willReturn($response);

        $client = new RspamdControllerClient(
            $this->httpClient,
            'http://rspamd:11334',
            '',
            2500
        );

        $result = $client->metrics();

        self::assertSame($metricsContent, $result);
    }

    public function testStatSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('{"scanned": 12345, "spam_count": 500}');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with('GET', 'http://rspamd:11334/stat', $this->anything())
            ->willReturn($response);

        $client = new RspamdControllerClient(
            $this->httpClient,
            'http://rspamd:11334',
            '',
            2500
        );

        $result = $client->stat();

        self::assertSame(12345, $result['scanned']);
        self::assertSame(500, $result['spam_count']);
    }

    public function testGraphSuccess(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('[{"ts": 1609459200, "spam": 10}]');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'http://rspamd:11334/graph',
                $this->callback(function (array $options) {
                    return isset($options['query']['type']) && 'hourly' === $options['query']['type'];
                })
            )
            ->willReturn($response);

        $client = new RspamdControllerClient(
            $this->httpClient,
            'http://rspamd:11334',
            '',
            2500
        );

        $result = $client->graph('hourly');

        self::assertIsArray($result);
        self::assertCount(1, $result);
    }

    public function testAuthenticationFailure(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(401);
        $response->method('getContent')->willReturn('Unauthorized');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $client = new RspamdControllerClient(
            $this->httpClient,
            'http://rspamd:11334',
            'wrong-password',
            2500
        );

        $this->expectException(RspamdClientException::class);
        $this->expectExceptionCode(RspamdClientException::ERROR_AUTH);

        $client->stat();
    }

    public function testInvalidEndpoint(): void
    {
        $client = new RspamdControllerClient(
            $this->httpClient,
            'http://rspamd:11334',
            '',
            2500
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not in the allowed read-only list');

        // Using reflection to test private method behavior through public interface
        // The /statreset endpoint is not in the allowed list
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('request');

        $method->invoke($client, 'POST', '/statreset');
    }

    public function testPasswordHeader(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getContent')->willReturn('{}');

        $this->httpClient
            ->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'http://rspamd:11334/stat',
                $this->callback(function (array $options) {
                    return isset($options['headers']['Password'])
                        && 'my-secret' === $options['headers']['Password'];
                })
            )
            ->willReturn($response);

        $client = new RspamdControllerClient(
            $this->httpClient,
            'http://rspamd:11334',
            'my-secret',
            2500
        );

        $client->stat();
    }
}
