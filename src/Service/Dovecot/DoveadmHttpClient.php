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
use App\Service\Dovecot\DTO\OldStatsDumpDto;
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
 * Supports both X-Dovecot-API key authentication and Basic authentication.
 *
 * @see https://doc.dovecot.org/admin_manual/doveadm_http_api/
 */
readonly class DoveadmHttpClient
{
    private const array ALLOWED_URL_SCHEMES = ['http', 'https'];
    private const int DEFAULT_TIMEOUT_MS = 2500;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(default::string:DOVEADM_HTTP_URL)%')]
        private ?string $httpUrl,
        #[Autowire('%env(default::string:DOVEADM_API_KEY_B64)%')]
        private ?string $apiKeyB64,
        #[Autowire('%env(default::string:DOVEADM_BASIC_USER)%')]
        private ?string $basicUser,
        #[Autowire('%env(default::string:DOVEADM_BASIC_PASSWORD)%')]
        private ?string $basicPassword,
        #[Autowire('%env(default::int:DOVEADM_TIMEOUT_MS)%')]
        private ?int $timeoutMs,
        #[Autowire('%env(default::bool:DOVEADM_VERIFY_SSL)%')]
        private bool $verifySsl = true,
    ) {
    }

    /**
     * Check if the Doveadm HTTP API is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->httpUrl);
    }

    /**
     * Get the health status of the Doveadm API connection.
     */
    public function checkHealth(): DoveadmHealthDto
    {
        if (!$this->isConfigured()) {
            return DoveadmHealthDto::notConfigured();
        }

        try {
            $this->oldStatsDump();

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
     * Execute the oldStatsDump command.
     *
     * @param string      $type       The stats type (e.g., "global")
     * @param string|null $filter     Optional filter expression
     * @param string|null $socketPath Optional socket path
     *
     * @throws DoveadmConnectionException  When the API cannot be reached
     * @throws DoveadmAuthenticationException When authentication fails
     * @throws DoveadmResponseException    When the response format is unexpected
     */
    public function oldStatsDump(
        string $type = 'global',
        ?string $filter = null,
        ?string $socketPath = null,
    ): OldStatsDumpDto {
        $parameters = ['type' => $type];

        if (null !== $filter) {
            $parameters['filter'] = $filter;
        }

        if (null !== $socketPath) {
            $parameters['socketPath'] = $socketPath;
        }

        $tag = 'stats_' . bin2hex(random_bytes(4));
        $response = $this->executeCommand('oldStatsDump', $parameters, $tag);

        return $this->parseOldStatsDumpResponse($response, $type);
    }

    /**
     * Execute a Doveadm command.
     *
     * @param string               $command    The command name (e.g., "oldStatsDump")
     * @param array<string, mixed> $parameters The command parameters
     * @param string               $tag        A unique tag to identify the response
     *
     * @throws DoveadmConnectionException
     * @throws DoveadmAuthenticationException
     * @throws DoveadmResponseException
     *
     * @return array<string, mixed> The command result
     */
    private function executeCommand(string $command, array $parameters, string $tag): array
    {
        $this->validateUrl();

        $payload = [[$command, $parameters, $tag]];

        try {
            $response = $this->httpClient->request('POST', $this->httpUrl, [
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

            if ($statusCode < 200 || $statusCode >= 300) {
                throw new DoveadmResponseException('Unexpected status code: ' . $statusCode);
            }

            $data = $response->toArray();

            return $this->extractResult($data, $tag);
        } catch (TransportExceptionInterface $e) {
            throw new DoveadmConnectionException('Connection failed: ' . $e->getMessage(), 0, $e);
        } catch (ClientExceptionInterface $e) {
            if (401 === $e->getResponse()->getStatusCode() || 403 === $e->getResponse()->getStatusCode()) {
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
     * Build the request headers including authentication.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // Prefer API key authentication over Basic auth
        if (!empty($this->apiKeyB64)) {
            $headers['Authorization'] = 'X-Dovecot-API ' . $this->apiKeyB64;
        } elseif (!empty($this->basicUser) && !empty($this->basicPassword)) {
            $credentials = base64_encode($this->basicUser . ':' . $this->basicPassword);
            $headers['Authorization'] = 'Basic ' . $credentials;
        }

        return $headers;
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
     * @return array<string, mixed>
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

                // The result is typically an array with one element containing the stats
                return $result[0] ?? $result;
            }
        }

        throw new DoveadmResponseException('No matching response found for tag: ' . $tag);
    }

    /**
     * Parse the oldStatsDump response into a DTO.
     *
     * @param array<string, mixed> $response The raw response data
     * @param string               $type     The stats type requested
     *
     * @throws DoveadmResponseException
     */
    private function parseOldStatsDumpResponse(array $response, string $type): OldStatsDumpDto
    {
        $counters = [];
        $lastUpdateSeconds = null;
        $resetTimestamp = null;

        foreach ($response as $key => $value) {
            // Skip non-string keys
            if (!is_string($key)) {
                continue;
            }

            // Parse special fields
            if ('last_update' === $key) {
                $lastUpdateSeconds = $this->parseNumericValue($value);
                continue;
            }

            if ('reset_timestamp' === $key) {
                $resetTimestamp = (int) $this->parseNumericValue($value);
                continue;
            }

            // Parse all other values as counters if they're numeric
            $numericValue = $this->parseNumericValue($value);

            if (null !== $numericValue) {
                $counters[$key] = $numericValue;
            }
        }

        return new OldStatsDumpDto(
            type: $type,
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: $lastUpdateSeconds,
            resetTimestamp: $resetTimestamp,
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
