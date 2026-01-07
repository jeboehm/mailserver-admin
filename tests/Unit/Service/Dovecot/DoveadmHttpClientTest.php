<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Exception\Dovecot\DoveadmAuthenticationException;
use App\Exception\Dovecot\DoveadmConnectionException;
use App\Exception\Dovecot\DoveadmResponseException;
use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Dovecot\DTO\HealthStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DoveadmHttpClientTest extends TestCase
{
    public function testIsConfiguredReturnsFalseWhenUrlIsEmpty(): void
    {
        $client = $this->createClient(httpUrl: '');

        self::assertFalse($client->isConfigured());
    }

    public function testIsConfiguredReturnsTrueWhenUrlIsSet(): void
    {
        $client = $this->createClient(httpUrl: 'http://dovecot:8080/doveadm/v1');

        self::assertTrue($client->isConfigured());
    }

    public function testCheckHealthReturnsNotConfiguredWhenUrlIsEmpty(): void
    {
        $client = $this->createClient(httpUrl: '');
        $health = $client->checkHealth();

        self::assertSame(HealthStatus::WARNING, $health->status);
        self::assertStringContainsString('not configured', $health->message);
    }

    public function testStatsDumpParsesSuccessfulResponse(): void
    {
        $responseBody = json_encode([
            [
                'doveadmResponse',
                [
                    [
                        'num_logins' => '42',
                        'num_connected_sessions' => '5',
                        'auth_successes' => '100',
                        'auth_failures' => '3',
                        'last_update' => '1609459200.123',
                        'reset_timestamp' => '1609455600',
                    ],
                ],
                'stats_12345678',
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $result = $client->statsDump();

        self::assertSame(42, $result->getCounterAsInt('num_logins'));
        self::assertSame(5, $result->getCounterAsInt('num_connected_sessions'));
        self::assertSame(100, $result->getCounterAsInt('auth_successes'));
        self::assertSame(3, $result->getCounterAsInt('auth_failures'));
        self::assertEqualsWithDelta(1609459200.123, $result->lastUpdateSeconds, 0.001);
        self::assertSame(1609455600, $result->resetTimestamp);
    }

    public function testStatsDumpHandlesStringCounters(): void
    {
        $responseBody = json_encode([
            [
                'doveadmResponse',
                [
                    [
                        'disk_input' => '1073741824',
                        'disk_output' => '536870912',
                        'mail_read_bytes' => '268435456',
                        'user_cpu' => '123.456',
                        'sys_cpu' => '78.90',
                    ],
                ],
                'stats_test',
            ],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $result = $client->statsDump();

        // Integer values
        self::assertSame(1073741824, $result->getCounterAsInt('disk_input'));
        self::assertSame(536870912, $result->getCounterAsInt('disk_output'));

        // Float values
        self::assertEqualsWithDelta(123.456, $result->getCounterAsFloat('user_cpu'), 0.001);
        self::assertEqualsWithDelta(78.90, $result->getCounterAsFloat('sys_cpu'), 0.001);
    }

    public function testStatsDumpThrowsOnAuthenticationFailure(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 401]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $this->expectException(DoveadmAuthenticationException::class);
        $client->statsDump();
    }

    public function testStatsDumpThrowsOnConnectionFailure(): void
    {
        $mockResponse = new MockResponse('', ['error' => 'Connection refused']);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $this->expectException(DoveadmConnectionException::class);
        $client->statsDump();
    }

    public function testStatsDumpThrowsOnInvalidResponse(): void
    {
        $mockResponse = new MockResponse('not json', ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $this->expectException(DoveadmResponseException::class);
        $client->statsDump();
    }

    public function testStatsDumpThrowsOnMissingTagInResponse(): void
    {
        $responseBody = json_encode([
            ['doveadmResponse', [['num_logins' => '0']], 'wrong_tag'],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $this->expectException(DoveadmResponseException::class);
        $this->expectExceptionMessage('No matching response');
        $client->statsDump();
    }

    public function testStatsDumpHandlesErrorResponse(): void
    {
        $responseBody = json_encode([
            ['error', ['exitCode' => 'command not found'], 'stats_test'],
        ], JSON_THROW_ON_ERROR);

        $mockResponse = new MockResponse($responseBody, ['http_code' => 200]);
        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $this->expectException(DoveadmResponseException::class);
        $this->expectExceptionMessage('Doveadm error');
        $client->statsDump();
    }

    #[DataProvider('invalidUrlProvider')]
    public function testStatsDumpValidatesUrl(string $url, string $expectedMessage): void
    {
        $client = $this->createClient(httpUrl: $url);

        $this->expectException(DoveadmConnectionException::class);
        $this->expectExceptionMessage($expectedMessage);
        $client->statsDump();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUrlProvider(): iterable
    {
        yield 'empty url' => ['', 'not configured'];
        yield 'invalid format' => ['not-a-url', 'Invalid'];
        yield 'ftp scheme' => ['ftp://dovecot:8080/doveadm/v1', 'http or https'];
    }

    public function testRequestIncludesApiKeyHeader(): void
    {
        $requestHeaders = [];
        $mockResponse = new MockResponse(function ($method, $url, $options) use (&$requestHeaders) {
            $requestHeaders = $options['headers'] ?? [];

            return json_encode([
                ['doveadmResponse', [[]], 'stats_test'],
            ], JSON_THROW_ON_ERROR);
        }, ['http_code' => 200]);

        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
            apiKeyB64: 'dGVzdGtleQ==',
        );

        $client->statsDump();

        self::assertContains('Authorization: X-Dovecot-API dGVzdGtleQ==', $requestHeaders);
    }

    public function testRequestIncludesBasicAuthHeader(): void
    {
        $requestHeaders = [];
        $mockResponse = new MockResponse(function ($method, $url, $options) use (&$requestHeaders) {
            $requestHeaders = $options['headers'] ?? [];

            return json_encode([
                ['doveadmResponse', [[]], 'stats_test'],
            ], JSON_THROW_ON_ERROR);
        }, ['http_code' => 200]);

        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
            basicUser: 'admin',
            basicPassword: 'secret',
        );

        $client->statsDump();

        $expectedAuth = 'Authorization: Basic ' . base64_encode('admin:secret');
        self::assertContains($expectedAuth, $requestHeaders);
    }

    public function testApiKeyTakesPrecedenceOverBasicAuth(): void
    {
        $requestHeaders = [];
        $mockResponse = new MockResponse(function ($method, $url, $options) use (&$requestHeaders) {
            $requestHeaders = $options['headers'] ?? [];

            return json_encode([
                ['doveadmResponse', [[]], 'stats_test'],
            ], JSON_THROW_ON_ERROR);
        }, ['http_code' => 200]);

        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
            apiKeyB64: 'dGVzdGtleQ==',
            basicUser: 'admin',
            basicPassword: 'secret',
        );

        $client->statsDump();

        $hasApiKey = false;
        $hasBasic = false;

        foreach ($requestHeaders as $header) {
            if (str_starts_with($header, 'Authorization: X-Dovecot-API')) {
                $hasApiKey = true;
            }
            if (str_starts_with($header, 'Authorization: Basic')) {
                $hasBasic = true;
            }
        }

        self::assertTrue($hasApiKey, 'API key header should be present');
        self::assertFalse($hasBasic, 'Basic auth header should not be present when API key is set');
    }

    public function testRequestPayloadFormat(): void
    {
        $requestBody = '';
        $mockResponse = new MockResponse(function ($method, $url, $options) use (&$requestBody) {
            $requestBody = $options['body'] ?? '';

            return json_encode([
                ['doveadmResponse', [[]], 'stats_test'],
            ], JSON_THROW_ON_ERROR);
        }, ['http_code' => 200]);

        $httpClient = new MockHttpClient([$mockResponse]);

        $client = $this->createClient(
            httpClient: $httpClient,
            httpUrl: 'http://dovecot:8080/doveadm/v1',
        );

        $client->statsDump();

        $payload = json_decode($requestBody, true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);
        self::assertCount(1, $payload);

        $command = $payload[0];
        self::assertSame('statsDump', $command[0]);
        self::assertSame(false, $command[1]['reset']);
        self::assertIsString($command[2]); // tag
    }

    private function createClient(
        ?MockHttpClient $httpClient = null,
        string $httpUrl = '',
        string $apiKeyB64 = '',
        string $basicUser = '',
        string $basicPassword = '',
        int $timeoutMs = 2500,
    ): DoveadmHttpClient {
        return new DoveadmHttpClient(
            httpClient: $httpClient ?? new MockHttpClient(),
            httpUrl: $httpUrl ?: null,
            apiKeyB64: $apiKeyB64 ?: null,
            basicUser: $basicUser ?: null,
            basicPassword: $basicPassword ?: null,
            timeoutMs: $timeoutMs,
        );
    }
}
