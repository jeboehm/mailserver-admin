<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Rspamd\RspamdControllerClient;
use Doctrine\DBAL\Connection;
use Predis\ClientInterface;

readonly class ConnectionCheckService
{
    public function __construct(
        private Connection $connection,
        private ClientInterface $redis,
        private DoveadmHttpClient $doveadmHttpClient,
        private RspamdControllerClient $rspamdControllerClient,
    ) {
    }

    public function checkDoveadm(): ?string
    {
        $result = $this->doveadmHttpClient->checkHealth();

        if ($result->isHealthy()) {
            return null;
        }

        return (string) $result->message;
    }

    public function checkRspamd(): ?string
    {
        $result = $this->rspamdControllerClient->ping();

        if ($result->isOk()) {
            return null;
        }

        return (string) $result->message;
    }

    /**
     * Check both MySQL and Redis connections.
     *
     * @return array{mysql: string|null, redis: string|null}|array{mysql: string|null, redis: string|null, doveadm: string|null, rspamd: string|null}
     */
    public function checkAll(bool $all = false): array
    {
        $result = [
            'mysql' => $this->checkMySQL(),
            'redis' => $this->checkRedis(),
        ];

        if ($all) {
            $result['doveadm'] = $this->checkDoveadm();
            $result['rspamd'] = $this->checkRspamd();
        }

        return $result;
    }

    public function checkMySQL(): ?string
    {
        try {
            $this->connection->executeQuery('SELECT id FROM mail_users LIMIT 1');
        } catch (\Throwable $e) {
            return $this->formatMySQLError($e);
        }

        return null;
    }

    public function checkRedis(): ?string
    {
        try {
            $this->redis->ping();
        } catch (\Throwable $e) {
            return $this->formatRedisError($e);
        }

        return null;
    }

    private function formatMySQLError(\Throwable $e): string
    {
        $message = $e->getMessage();

        // Extract user-friendly error message
        if (str_contains($message, 'Access denied')) {
            return 'Authentication failed. Please check your database username and password.';
        }

        if (str_contains($message, 'Unknown database')) {
            return 'Database not found. Please ensure the database exists.';
        }

        if (str_contains($message, 'Connection refused') || str_contains($message, 'No connection')) {
            return 'Cannot connect to database server. Please check if MySQL is running and the host/port are correct.';
        }

        if (str_contains($message, 'Connection timed out')) {
            return 'Connection to database server timed out. Please check your network connection and firewall settings.';
        }

        if (str_contains($message, 'getaddrinfo failed') || str_contains($message, 'could not translate host name')) {
            return 'Cannot resolve database hostname. Please check your DATABASE_URL configuration.';
        }

        // Generic error - extract the core message
        $parts = explode(':', $message, 2);

        return trim($parts[1] ?? $message);
    }

    private function formatRedisError(\Throwable $e): string
    {
        $message = $e->getMessage();

        // Extract user-friendly error message
        if (str_contains($message, 'Connection refused') || str_contains($message, 'No connection')) {
            return 'Cannot connect to Redis server. Please check if Redis is running and the host/port are correct.';
        }

        if (str_contains($message, 'Connection timed out')) {
            return 'Connection to Redis server timed out. Please check your network connection and firewall settings.';
        }

        if (str_contains($message, 'AUTH') || str_contains($message, 'authentication')) {
            return 'Redis authentication failed. Please check your REDIS_PASSWORD configuration.';
        }

        if (str_contains($message, 'getaddrinfo failed') || str_contains($message, 'could not translate host name')) {
            return 'Cannot resolve Redis hostname. Please check your REDIS_HOST configuration.';
        }

        // Generic error - extract the core message
        $parts = explode(':', $message, 2);

        return trim($parts[1] ?? $message);
    }
}
