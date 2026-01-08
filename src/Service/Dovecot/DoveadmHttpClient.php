<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot;

use App\Exception\Dovecot\DoveadmAuthenticationException;
use App\Exception\Dovecot\DoveadmConnectionException;
use App\Exception\Dovecot\DoveadmResponseException;
use App\Service\Dovecot\DTO\DoveadmHealthDto;
use App\Service\Dovecot\DTO\StatsDumpDto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HTTP client for the Doveadm HTTP API.
 *
 * Sends commands to the Doveadm HTTP API and parses responses.
 *
 * @see https://doc.dovecot.org/2.4.2/core/admin/doveadm.html#http-api
 */
readonly class DoveadmHttpClient
{
    private const array ALLOWED_URL_SCHEMES = ['http', 'https'];
    private const int DEFAULT_TIMEOUT_MS = 2500;

    private ?string $apiKeyB64;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(resolve:string:DOVEADM_HTTP_URL)%')]
        private string $httpUrl,
        #[Autowire('%env(default::resolve:string:DOVEADM_API_KEY)%')]
        ?string $apiKey,
        #[Autowire('%env(default::int:DOVEADM_TIMEOUT_MS)%')]
        private ?int $timeoutMs,
        #[Autowire('%env(default::bool:DOVEADM_VERIFY_SSL)%')]
        private bool $verifySsl = true,
    ) {
        $this->apiKeyB64 = $apiKey ? base64_encode($apiKey) : null;
    }

    /**
     * Get the health status of the Doveadm API connection.
     *
     * Issues a GET request to /doveadm/v1 to retrieve the list of available commands.
     * This is a lightweight health check that verifies the API is reachable and authenticated.
     */
    public function checkHealth(): DoveadmHealthDto
    {
        if (!$this->isConfigured()) {
            return DoveadmHealthDto::notConfigured();
        }

        try {
            $commands = $this->listCommands();

            // Verify that we got a list of commands (non-empty array)
            if (!is_array($commands) || empty($commands)) {
                return DoveadmHealthDto::formatError('Expected list of commands, got empty or invalid response');
            }

            return DoveadmHealthDto::ok(new \DateTimeImmutable());
        } catch (DoveadmConnectionException $e) {
            return DoveadmHealthDto::connectionError($e->getMessage());
        } catch (DoveadmAuthenticationException) {
            return DoveadmHealthDto::authenticationError();
        } catch (DoveadmResponseException $e) {
            return DoveadmHealthDto::formatError($e->getMessage());
        }
    }

    /**
     * Check if the Doveadm HTTP API is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->httpUrl);
    }

    /**
     * Execute the statsDump command.
     *
     * @param string|null $socketPath Optional socket path
     *
     * @throws DoveadmConnectionException     When the API cannot be reached
     * @throws DoveadmAuthenticationException When authentication fails
     * @throws DoveadmResponseException       When the response format is unexpected
     */
    public function statsDump(
        ?string $socketPath = null,
    ): StatsDumpDto {
        $parameters = [
            'reset' => false,
        ];

        if (null !== $socketPath) {
            $parameters['socketPath'] = $socketPath;
        }

        $tag = 'stats_' . bin2hex(random_bytes(4));
        $response = $this->executeCommand('statsDump', $parameters, $tag);

        return $this->parseStatsDumpResponse($response);
    }

    /**
     * List available commands by issuing a GET request to /doveadm/v1.
     *
     * @throws DoveadmConnectionException     When the API cannot be reached
     * @throws DoveadmAuthenticationException When authentication fails
     * @throws DoveadmResponseException       When the response format is unexpected
     *
     * @return array<int|string, mixed> List of available commands
     */
    private function listCommands(): array
    {
        $this->validateUrl();

        $url = $this->buildApiUrl();

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $this->buildHeaders(false),
                'timeout' => ($this->timeoutMs ?? self::DEFAULT_TIMEOUT_MS) / 1000,
                'verify_host' => $this->verifySsl,
                'verify_peer' => $this->verifySsl,
            ]);

            $statusCode = $response->getStatusCode();

            if (401 === $statusCode || 403 === $statusCode) {
                throw new DoveadmAuthenticationException('Authentication failed with status code ' . $statusCode);
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new DoveadmResponseException('Unexpected status code: ' . $statusCode);
            }

            $data = $response->toArray();

            // The response should be an array of commands
            if (!is_array($data)) {
                throw new DoveadmResponseException('Expected array response, got ' . gettype($data));
            }

            return $data;
        } catch (TransportExceptionInterface $e) {
            throw new DoveadmConnectionException('Connection failed: ' . $e->getMessage(), 0, $e);
        } catch (ClientExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if (401 === $statusCode || 403 === $statusCode) {
                throw new DoveadmAuthenticationException('Authentication failed', 0, $e);
            }
            throw new DoveadmResponseException('Client error: ' . $e->getMessage(), 0, $e);
        } catch (ServerExceptionInterface $e) {
            throw new DoveadmResponseException('Server error: ' . $e->getMessage(), 0, $e);
        } catch (RedirectionExceptionInterface $e) {
            throw new DoveadmResponseException('Unexpected redirect: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new DoveadmResponseException('Invalid JSON response: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute a Doveadm command.
     *
     * @param string               $command    The command name (e.g., "statsDump")
     * @param array<string, mixed> $parameters The command parameters
     * @param string               $tag        A unique tag to identify the response
     *
     * @throws DoveadmAuthenticationException
     * @throws DoveadmResponseException
     * @throws DoveadmConnectionException
     *
     * @return array<int|string, mixed> The command result (format depends on command)
     */
    private function executeCommand(string $command, array $parameters, string $tag): array
    {
        $this->validateUrl();

        $payload = [[$command, $parameters, $tag]];
        $url = $this->buildApiUrl();

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->buildHeaders(),
                'json' => $payload,
                'timeout' => ($this->timeoutMs ?? self::DEFAULT_TIMEOUT_MS) / 1000,
                'verify_host' => $this->verifySsl,
                'verify_peer' => $this->verifySsl,
            ]);

            $statusCode = $response->getStatusCode();

            if (401 === $statusCode || 403 === $statusCode) {
                throw new DoveadmAuthenticationException('Authentication failed with status code ' . $statusCode);
            }

            if (404 === $statusCode) {
                throw new DoveadmResponseException('Unknown doveadm command: ' . $command . ' (HTTP 404)');
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new DoveadmResponseException('Unexpected status code: ' . $statusCode);
            }

            $data = $response->toArray();

            return $this->extractResult($data, $tag);
        } catch (TransportExceptionInterface $e) {
            throw new DoveadmConnectionException('Connection failed: ' . $e->getMessage(), 0, $e);
        } catch (ClientExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if (401 === $statusCode || 403 === $statusCode) {
                throw new DoveadmAuthenticationException('Authentication failed', 0, $e);
            }
            if (404 === $statusCode) {
                throw new DoveadmResponseException('Unknown doveadm command: ' . $command . ' (HTTP 404)', 0, $e);
            }
            throw new DoveadmResponseException('Client error: ' . $e->getMessage(), 0, $e);
        } catch (ServerExceptionInterface $e) {
            throw new DoveadmResponseException('Server error: ' . $e->getMessage(), 0, $e);
        } catch (RedirectionExceptionInterface $e) {
            throw new DoveadmResponseException('Unexpected redirect: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new DoveadmResponseException('Invalid JSON response: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate the configured URL to prevent SSRF.
     *
     * @throws DoveadmConnectionException
     */
    private function validateUrl(): void
    {
        if (empty($this->httpUrl)) {
            throw new DoveadmConnectionException('DOVEADM_HTTP_URL is not configured');
        }

        $parsed = parse_url($this->httpUrl);

        if (false === $parsed || !isset($parsed['scheme'], $parsed['host'])) {
            throw new DoveadmConnectionException('Invalid DOVEADM_HTTP_URL format');
        }

        if (!in_array(strtolower($parsed['scheme']), self::ALLOWED_URL_SCHEMES, true)) {
            throw new DoveadmConnectionException('DOVEADM_HTTP_URL must use http or https scheme');
        }
    }

    /**
     * Build the full API URL with /doveadm/v1 path.
     *
     * According to the Dovecot documentation, all commands must be sent to /doveadm/v1.
     * This method ensures the path is appended if not already present.
     *
     * @throws DoveadmConnectionException
     */
    private function buildApiUrl(): string
    {
        if (empty($this->httpUrl)) {
            throw new DoveadmConnectionException('DOVEADM_HTTP_URL is not configured');
        }

        $parsed = parse_url($this->httpUrl);

        if (false === $parsed) {
            throw new DoveadmConnectionException('Invalid DOVEADM_HTTP_URL format');
        }

        // Remove trailing slash from base URL
        $baseUrl = rtrim($this->httpUrl, '/');

        // Check if /doveadm/v1 is already in the path
        $path = $parsed['path'] ?? '';
        if (str_ends_with($path, '/doveadm/v1')) {
            return $baseUrl;
        }

        // Append /doveadm/v1 if not present
        return $baseUrl . '/doveadm/v1';
    }

    /**
     * Build the request headers including authentication.
     *
     * @param bool $includeContentType Whether to include Content-Type header (for POST requests)
     *
     * @return array<string, string>
     */
    private function buildHeaders(bool $includeContentType = true): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($includeContentType) {
            $headers['Content-Type'] = 'application/json';
        }

        if (!empty($this->apiKeyB64)) {
            $headers['Authorization'] = 'X-Dovecot-API ' . $this->apiKeyB64;
        }

        return $headers;
    }

    /**
     * Extract the result from the Doveadm response by tag.
     *
     * The response format is an array of tuples:
     * [["doveadmResponse", [{ ...result... }], "tag"]]
     *
     * @param array<int, mixed> $data The full response data
     * @param string            $tag  The tag to match
     *
     * @throws DoveadmResponseException
     *
     * @return array<int|string, mixed> The result data (array of objects for statsDump, or single object/array for other commands)
     */
    private function extractResult(array $data, string $tag): array
    {
        foreach ($data as $item) {
            if (!is_array($item) || count($item) < 3) {
                continue;
            }

            $responseType = $item[0] ?? null;
            $result = $item[1] ?? null;
            $responseTag = $item[2] ?? null;

            // Check for error response
            if ('error' === $responseType && $responseTag === $tag) {
                $errorMessage = $result['exitCode'] ?? 'Unknown error';
                throw new DoveadmResponseException('Doveadm error: ' . $errorMessage);
            }

            // Match doveadmResponse by tag
            if ('doveadmResponse' === $responseType && $responseTag === $tag) {
                if (!is_array($result)) {
                    throw new DoveadmResponseException('Expected array result in doveadmResponse');
                }

                // The result is an array of metric objects for statsDump
                // or a single object/array for other commands
                return $result;
            }
        }

        throw new DoveadmResponseException('No matching response found for tag: ' . $tag);
    }

    /**
     * Parse the statsDump response into a DTO.
     *
     * The response format can be either:
     * 1. An array of metric objects: [{"metric_name":"auth_successes","field":"duration","count":0,...}, ...]
     * 2. A flat array of counters: [{"num_logins":"42","auth_successes":"100",...}]
     *
     * @param array<int, array<string, mixed>> $response The raw response data
     *
     * @throws DoveadmResponseException
     */
    private function parseStatsDumpResponse(array $response): StatsDumpDto
    {
        $counters = [];

        foreach ($response as $metric) {
            if (!is_array($metric)) {
                continue;
            }

            $metricName = $metric['metric_name'] ?? null;
            $field = $metric['field'] ?? null;

            if (!is_string($metricName) || !is_string($field)) {
                continue;
            }

            // Create a composite key: metric_name.field
            $counterKey = $metricName . '.' . $field;

            // Extract all numeric values from the metric
            // We'll store the 'count' as the primary counter value
            // and other stats as separate counters with suffixes
            $count = $this->parseNumericValue($metric['count'] ?? null);
            if (null !== $count) {
                $counters[$counterKey] = $count;
                // Also store with simple metric name for easy access (template expects this format)
                // Only store if not already set to avoid overwriting with different field values
                if (!isset($counters[$metricName])) {
                    $counters[$metricName] = $count;
                }
            }

            // Store additional statistics with suffixes
            $statsFields = ['sum', 'min', 'max', 'avg', 'median', 'stddev', '%95'];
            foreach ($statsFields as $statField) {
                if (isset($metric[$statField])) {
                    $statValue = $this->parseNumericValue($metric[$statField]);
                    if (null !== $statValue) {
                        // Use a safe key format (replace % with pct)
                        $safeStatField = str_replace('%', 'pct', $statField);
                        $counters[$counterKey . '.' . $safeStatField] = $statValue;
                    }
                }
            }
        }

        return new StatsDumpDto(
            fetchedAt: new \DateTimeImmutable(),
            counters: $counters,
        );
    }

    /**
     * Parse a value that may be a string representation of a number.
     *
     * Dovecot's API returns numeric values as strings (e.g., "0", "123").
     */
    private function parseNumericValue(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        // Check if it's a valid numeric string
        if (!is_numeric($value)) {
            return null;
        }

        // Check if it contains a decimal point
        if (str_contains($value, '.')) {
            return (float) $value;
        }

        return (int) $value;
    }
}
