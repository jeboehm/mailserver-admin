<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Doctrine\DBAL\Connection;
use Predis\ClientInterface;

readonly class ConnectionCheckService
{
    public function __construct(
        private Connection $connection,
        private ClientInterface $redis,
    ) {
    }

    /**
     * Check MySQL connection and return error message if failed.
     *
     * @return string|null Error message if connection failed, null if successful
     */
    public function checkMySQL(): ?string
    {
        try {
            $this->connection->executeQuery('SELECT id FROM mail_domains LIMIT 1');
        } catch (\Throwable $e) {
            return $this->formatMySQLError($e);
        }

        return null;
    }

    /**
     * Check Redis connection and return error message if failed.
     *
     * @return string|null Error message if connection failed, null if successful
     */
    public function checkRedis(): ?string
    {
        try {
            $this->redis->ping();
        } catch (\Throwable $e) {
            return $this->formatRedisError($e);
        }

        return null;
    }

    /**
     * Check both MySQL and Redis connections.
     *
     * @return array{mysql: string|null, redis: string|null}
     */
    public function checkAll(): array
    {
        return [
            'mysql' => $this->checkMySQL(),
            'redis' => $this->checkRedis(),
        ];
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
        $coreMessage = trim($parts[1] ?? $message);

        return $coreMessage;
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
        $coreMessage = trim($parts[1] ?? $message);

        return $coreMessage;
    }
}
