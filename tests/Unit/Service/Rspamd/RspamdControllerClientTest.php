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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RspamdControllerClientTest extends TestCase
{
    private const string CONTROLLER_URL = 'http://localhost:11334';
    private const string PASSWORD = 'test-password';

    public function testPingReturnsOkWhenHealthy(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('pong', ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $health = $client->ping();

        self::assertInstanceOf(HealthDto::class, $health);
        self::assertTrue($health->isOk());
        self::assertEquals('Rspamd is healthy', $health->message);
        self::assertEquals(200, $health->httpStatus);
        self::assertNotNull($health->latencyMs);
    }

    public function testPingReturnsCriticalWhenUrlNotConfigured(): void
    {
        $httpClient = new MockHttpClient();
        $client = new RspamdControllerClient(
            $httpClient,
            '',
            2500,
            self::PASSWORD,
        );

        $health = $client->ping();

        self::assertTrue($health->isCritical());
        self::assertEquals('Rspamd controller URL not configured', $health->message);
    }

    public function testPingReturnsWarningWhenUnexpectedResponse(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('unexpected', ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $health = $client->ping();

        self::assertTrue($health->isWarning());
        self::assertEquals('Unexpected ping response', $health->message);
        self::assertEquals(200, $health->httpStatus);
    }

    public function testPingReturnsCriticalOnAuthFailure(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('Unauthorized', ['http_code' => 401]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $health = $client->ping();

        self::assertTrue($health->isCritical());
        self::assertEquals('Controller reachable, authentication failed', $health->message);
        self::assertEquals(401, $health->httpStatus);
    }

    public function testPingReturnsCriticalOnTimeout(): void
    {
        $httpClient = new MockHttpClient([
            static function () {
                throw new TransportException('Connection timed out');
            },
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $health = $client->ping();

        self::assertTrue($health->isCritical());
        self::assertEquals('Connection timeout', $health->message);
        self::assertNotNull($health->latencyMs);
    }

    public function testStatReturnsArray(): void
    {
        $statData = ['scanned' => 100, 'spam_count' => 5];
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($statData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $result = $client->stat();

        self::assertEquals($statData, $result);
    }

    public function testGraphReturnsArray(): void
    {
        $graphData = [[['x' => 1234567890, 'y' => 10]]];
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($graphData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $result = $client->graph('day');

        self::assertEquals($graphData, $result);
    }

    public function testPieReturnsArray(): void
    {
        $pieData = [['action' => 'reject', 'value' => 10]];
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($pieData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $result = $client->pie();

        self::assertEquals($pieData, $result);
    }

    public function testActionsReturnsArray(): void
    {
        $actionsData = [['action' => 'reject', 'value' => 15]];
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($actionsData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $result = $client->actions();

        self::assertEquals($actionsData, $result);
    }

    public function testCountersReturnsArray(): void
    {
        $countersData = [['symbol' => 'TEST_SYMBOL', 'hits' => 5]];
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($countersData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $result = $client->counters();

        self::assertEquals($countersData, $result);
    }

    public function testHistoryReturnsArray(): void
    {
        $historyData = ['rows' => [['time' => 1234567890, 'action' => 'reject']]];
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($historyData), ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $result = $client->history(50);

        self::assertEquals($historyData, $result);
    }

    public function testRequestJsonThrowsOnInvalidJson(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('invalid json', ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $this->expectException(RspamdClientException::class);
        $this->expectExceptionCode(RspamdClientException::ERROR_FORMAT);

        $client->stat();
    }

    public function testRequestJsonThrowsOnNonArrayJson(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('"string"', ['http_code' => 200]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $this->expectException(RspamdClientException::class);
        $this->expectExceptionCode(RspamdClientException::ERROR_FORMAT);

        $client->stat();
    }

    public function testRequestThrowsOnInvalidEndpoint(): void
    {
        $httpClient = new MockHttpClient();
        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $this->expectException(\InvalidArgumentException::class);

        $clientReflection = new \ReflectionClass($client);
        $method = $clientReflection->getMethod('request');
        $method->setAccessible(true);
        $method->invoke($client, 'GET', '/invalid-endpoint');
    }

    public function testRequestThrowsOnConnectionFailure(): void
    {
        $httpClient = new MockHttpClient([
            static function () {
                throw new TransportException('Connection refused');
            },
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $this->expectException(RspamdClientException::class);
        $this->expectExceptionCode(RspamdClientException::ERROR_CONNECTION);

        $client->stat();
    }

    public function testRequestThrowsOnUpstreamError(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('Error', ['http_code' => 500]),
        ]);

        $client = new RspamdControllerClient(
            $httpClient,
            self::CONTROLLER_URL,
            2500,
            self::PASSWORD,
        );

        $this->expectException(RspamdClientException::class);
        $this->expectExceptionCode(RspamdClientException::ERROR_UPSTREAM);

        $client->stat();
    }
}
