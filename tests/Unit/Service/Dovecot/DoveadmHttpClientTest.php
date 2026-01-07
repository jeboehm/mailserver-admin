<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Exception\Dovecot\DoveadmConnectionException;
use App\Exception\Dovecot\DoveadmResponseException;
use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Dovecot\DTO\DoveadmHealthDto;
use App\Service\Dovecot\DTO\StatsDumpDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DoveadmHttpClientTest extends TestCase
{
    private const string HTTP_URL = 'http://localhost:8080';
    private const string API_KEY = 'test-api-key';

    public function testIsConfiguredReturnsTrueWhenUrlIsSet(): void
    {
        $httpClient = new MockHttpClient();
        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        self::assertTrue($client->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenUrlIsEmpty(): void
    {
        $httpClient = new MockHttpClient();
        $client = new DoveadmHttpClient(
            $httpClient,
            '',
            self::API_KEY,
            null,
            true,
        );

        self::assertFalse($client->isConfigured());
    }

    public function testIsConfiguredReturnsFalseWhenUrlIsNull(): void
    {
        $httpClient = new MockHttpClient();
        $client = new DoveadmHttpClient(
            $httpClient,
            null,
            self::API_KEY,
            null,
            true,
        );

        self::assertFalse($client->isConfigured());
    }

    public function testCheckHealthReturnsNotConfiguredWhenUrlNotSet(): void
    {
        $httpClient = new MockHttpClient();
        $client = new DoveadmHttpClient(
            $httpClient,
            null,
            self::API_KEY,
            null,
            true,
        );

        $health = $client->checkHealth();

        self::assertInstanceOf(DoveadmHealthDto::class, $health);
        self::assertFalse($health->isHealthy());
        self::assertStringContainsString('not configured', $health->message);
    }

    public function testCheckHealthReturnsOkWhenStatsDumpSucceeds(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                // checkHealth calls statsDump which makes a POST request
                if ('POST' === $method) {
                    $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                    $tag = $payload[0][2] ?? 'stats_abc123';

                    $response = [
                        [
                            'doveadmResponse',
                            [
                                [
                                    'metric_name' => 'num_logins',
                                    'field' => 'count',
                                    'count' => '42',
                                ],
                            ],
                            $tag,
                        ],
                    ];

                    return new MockResponse(json_encode($response), ['http_code' => 200]);
                }

                return new MockResponse('[]', ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $health = $client->checkHealth();

        self::assertTrue($health->isHealthy());
        self::assertNotNull($health->lastSuccessfulFetch);
    }

    public function testCheckHealthReturnsConnectionErrorOnTransportException(): void
    {
        $httpClient = new MockHttpClient([
            function (): void {
                throw new TransportException('Connection refused');
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $health = $client->checkHealth();

        self::assertFalse($health->isHealthy());
        self::assertStringContainsString('Cannot connect', $health->message);
    }

    public function testCheckHealthReturnsAuthenticationErrorOn401(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse('Unauthorized', ['http_code' => 401]),
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $health = $client->checkHealth();

        self::assertFalse($health->isHealthy());
        self::assertStringContainsString('Authentication failed', $health->message);
    }

    public function testStatsDumpReturnsStatsDumpDto(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'count',
                                'count' => '42',
                            ],
                            [
                                'metric_name' => 'auth_successes',
                                'field' => 'count',
                                'count' => '100',
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump();

        self::assertInstanceOf(StatsDumpDto::class, $stats);
        self::assertSame(42, $stats->getCounter('num_logins'));
        self::assertSame(100, $stats->getCounter('auth_successes'));
    }

    public function testStatsDumpParsesFlatFormat(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'num_logins' => '42',
                                'auth_successes' => '100',
                                'last_update' => '1704106800.123',
                                'reset_timestamp' => '1704103200',
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump();

        self::assertInstanceOf(StatsDumpDto::class, $stats);
        self::assertSame(42, $stats->getCounter('num_logins'));
        self::assertSame(100, $stats->getCounter('auth_successes'));
        self::assertSame(1704106800.123, $stats->lastUpdateSeconds);
        self::assertSame(1704103200, $stats->resetTimestamp);
    }

    public function testStatsDumpWithSocketPath(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'count',
                                'count' => '42',
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump('/var/run/dovecot/stats');

        self::assertInstanceOf(StatsDumpDto::class, $stats);
    }

    public function testStatsDumpThrowsOnErrorResponse(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'error',
                        ['exitCode' => 'Command failed'],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $this->expectException(DoveadmResponseException::class);
        $this->expectExceptionMessage('Doveadm error');

        $client->statsDump();
    }

    public function testStatsDumpThrowsOnMissingResponse(): void
    {
        $response = [
            [
                'doveadmResponse',
                [],
                'different_tag',
            ],
        ];

        $httpClient = new MockHttpClient([
            new MockResponse(json_encode($response), ['http_code' => 200]),
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $this->expectException(DoveadmResponseException::class);
        $this->expectExceptionMessage('No matching response found');

        $client->statsDump();
    }

    public function testStatsDumpThrowsOnTransportException(): void
    {
        $httpClient = new MockHttpClient([
            function (): void {
                throw new TransportException('Connection refused');
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $this->expectException(DoveadmConnectionException::class);

        $client->statsDump();
    }

    public function testStatsDumpThrowsOnClientException(): void
    {
        $response = new MockResponse('Bad Request', ['http_code' => 400]);
        $exception = new ClientException($response);

        $httpClient = new MockHttpClient([
            function () use ($exception): void {
                throw $exception;
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $this->expectException(DoveadmResponseException::class);

        $client->statsDump();
    }

    public function testStatsDumpThrowsOnServerException(): void
    {
        $response = new MockResponse('Internal Server Error', ['http_code' => 500]);
        $exception = new ServerException($response);

        $httpClient = new MockHttpClient([
            function () use ($exception): void {
                throw $exception;
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $this->expectException(DoveadmResponseException::class);
        $this->expectExceptionMessage('Server error');

        $client->statsDump();
    }

    public function testStatsDumpThrowsOnRedirectionException(): void
    {
        $response = new MockResponse('Moved', ['http_code' => 301]);
        $exception = new RedirectionException($response);

        $httpClient = new MockHttpClient([
            function () use ($exception): void {
                throw $exception;
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $this->expectException(DoveadmResponseException::class);
        $this->expectExceptionMessage('Unexpected redirect');

        $client->statsDump();
    }

    public function testBuildApiUrlAppendsDoveadmV1(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'count',
                                'count' => '42',
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            'http://localhost:8080',
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump();

        self::assertInstanceOf(StatsDumpDto::class, $stats);
    }

    public function testBuildApiUrlPreservesExistingPath(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'count',
                                'count' => '42',
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            'http://localhost:8080/doveadm/v1',
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump();

        self::assertInstanceOf(StatsDumpDto::class, $stats);
    }

    public function testApiKeyIsBase64Encoded(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'count',
                                'count' => '42',
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump();

        self::assertInstanceOf(StatsDumpDto::class, $stats);
    }

    public function testStatsDumpParsesNumericValuesAsStrings(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'sum',
                                'count' => '42',
                            ],
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'count',
                                'count' => '43.12',
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump();

        self::assertSame(42, $stats->getCounter('num_logins.sum'));
        self::assertSame(43.12, $stats->getCounter('num_logins.count'));
    }

    public function testStatsDumpHandlesMissingCounters(): void
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options) {
                $payload = json_decode($options['body'], true, 5, JSON_THROW_ON_ERROR);
                $tag = $payload[0][2] ?? 'unknown';

                $response = [
                    [
                        'doveadmResponse',
                        [
                            [
                                'metric_name' => 'num_logins',
                                'field' => 'count',
                                // count is missing
                            ],
                        ],
                        $tag,
                    ],
                ];

                return new MockResponse(json_encode($response), ['http_code' => 200]);
            },
        ]);

        $client = new DoveadmHttpClient(
            $httpClient,
            self::HTTP_URL,
            self::API_KEY,
            null,
            true,
        );

        $stats = $client->statsDump();

        self::assertInstanceOf(StatsDumpDto::class, $stats);
        self::assertNull($stats->getCounter('num_logins'));
    }
}
