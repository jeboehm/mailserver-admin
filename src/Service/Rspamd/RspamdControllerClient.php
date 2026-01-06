<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Rspamd;

use App\Service\Rspamd\DTO\HealthDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client for Rspamd controller worker endpoints.
 * Only calls read-only endpoints, never privileged actions.
 */
final readonly class RspamdControllerClient
{
    private const array READ_ONLY_ENDPOINTS = [
        '/ping',
        '/ready',
        '/healthy',
        '/stat',
        '/metrics',
        '/graph',
        '/pie',
        '/counters',
        '/history',
        '/actions',
        '/symbols',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default::string:RSPAMD_CONTROLLER_URL)%')]
        private string $controllerUrl,
        #[Autowire('%env(default::string:RSPAMD_PASSWORD)%')]
        private string $password,
        #[Autowire('%env(default:rspamd_timeout_default:int:RSPAMD_TIMEOUT_MS)%')]
        private int $timeoutMs,
    ) {
    }

    /**
     * Check Rspamd health via /ping endpoint.
     */
    public function ping(): HealthDto
    {
        if ('' === $this->controllerUrl) {
            return HealthDto::critical('Rspamd controller URL not configured');
        }

        $startTime = microtime(true);

        try {
            $response = $this->request('GET', '/ping');
            $latencyMs = (microtime(true) - $startTime) * 1000;
            $statusCode = $response['_status_code'];
            $content = $response['_content'] ?? '';

            if (200 === $statusCode && 'pong' === strtolower(trim($content))) {
                return HealthDto::ok('Rspamd is healthy', $latencyMs);
            }

            return HealthDto::warning('Unexpected ping response', $statusCode, $latencyMs);
        } catch (RspamdClientException $e) {
            $latencyMs = (microtime(true) - $startTime) * 1000;

            if (RspamdClientException::ERROR_AUTH === $e->getCode()) {
                return HealthDto::critical('Controller reachable, authentication failed', 401, $latencyMs);
            }

            if (RspamdClientException::ERROR_TIMEOUT === $e->getCode()) {
                return HealthDto::critical('Connection timeout', null, $latencyMs);
            }

            return HealthDto::critical($e->getMessage(), null, $latencyMs);
        }
    }

    /**
     * Get Prometheus-format metrics from /metrics.
     */
    public function metrics(): string
    {
        $response = $this->request('GET', '/metrics');

        return $response['_content'] ?? '';
    }

    /**
     * Get statistics from /stat endpoint.
     *
     * @return array<string, mixed>
     */
    public function stat(): array
    {
        return $this->requestJson('GET', '/stat');
    }

    /**
     * Get time series data from /graph endpoint.
     *
     * @return array<string, mixed>
     */
    public function graph(string $type = 'hourly'): array
    {
        return $this->requestJson('GET', '/graph', ['type' => $type]);
    }

    /**
     * Get action distribution from /pie endpoint.
     *
     * @return array<string, mixed>
     */
    public function pie(): array
    {
        return $this->requestJson('GET', '/pie');
    }

    /**
     * Get action thresholds from /actions endpoint.
     *
     * @return array<string, mixed>
     */
    public function actions(): array
    {
        return $this->requestJson('GET', '/actions');
    }

    /**
     * Get symbol counters from /counters endpoint.
     *
     * @return array<string, mixed>
     */
    public function counters(): array
    {
        return $this->requestJson('GET', '/counters');
    }

    /**
     * Get scan history from /history endpoint.
     *
     * @return array<string, mixed>
     */
    public function history(int $limit = 100): array
    {
        return $this->requestJson('GET', '/history', ['limit' => $limit]);
    }

    /**
     * Get symbol information from /symbols endpoint.
     *
     * @return array<string, mixed>
     */
    public function symbols(): array
    {
        return $this->requestJson('GET', '/symbols');
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $endpoint, array $query = []): array
    {
        $response = $this->request($method, $endpoint, $query);
        $content = $response['_content'] ?? '';

        if ('' === $content) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw RspamdClientException::invalidFormat('Invalid JSON response', $e);
        }

        if (!\is_array($data)) {
            throw RspamdClientException::invalidFormat('Expected JSON object or array');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $query = []): array
    {
        if ('' === $this->controllerUrl) {
            throw RspamdClientException::connectionFailed('(not configured)');
        }

        $this->validateEndpoint($endpoint);

        $url = rtrim($this->controllerUrl, '/') . $endpoint;

        $options = [
            'timeout' => $this->timeoutMs / 1000,
        ];

        if ([] !== $query) {
            $options['query'] = $query;
        }

        if ('' !== $this->password) {
            $options['headers'] = [
                'Password' => $this->password,
            ];
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if (401 === $statusCode || 403 === $statusCode) {
                throw RspamdClientException::authenticationFailed();
            }

            if ($statusCode >= 400) {
                throw RspamdClientException::upstreamError($statusCode, $content);
            }

            return [
                '_status_code' => $statusCode,
                '_content' => $content,
            ];
        } catch (TransportExceptionInterface $e) {
            $message = $e->getMessage();

            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                throw RspamdClientException::timeout($url, $e);
            }

            throw RspamdClientException::connectionFailed($url, $e);
        } catch (HttpExceptionInterface $e) {
            throw RspamdClientException::upstreamError(
                $e->getResponse()->getStatusCode(),
                $e->getMessage(),
                $e
            );
        }
    }

    private function validateEndpoint(string $endpoint): void
    {
        $normalizedEndpoint = '/' . ltrim(explode('?', $endpoint)[0], '/');

        foreach (self::READ_ONLY_ENDPOINTS as $allowed) {
            if ($normalizedEndpoint === $allowed) {
                return;
            }
        }

        throw new \InvalidArgumentException(
            \sprintf('Endpoint "%s" is not in the allowed read-only list', $endpoint)
        );
    }
}
