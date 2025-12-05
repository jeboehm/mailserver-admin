<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service;

use App\Service\ConnectionCheckService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

class ConnectionCheckServiceTest extends TestCase
{
    private MockObject|Connection $connection;
    private MockObject|ClientInterface $redis;
    private ConnectionCheckService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->redis = $this->createMock(ClientInterface::class);
        $this->service = new ConnectionCheckService($this->connection, $this->redis);
    }

    public function testCheckMySQLSuccess(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willReturn($this->createMock(Result::class));

        $result = $this->service->checkMySQL();

        $this->assertNull($result);
    }

    public function testCheckMySQLFailureAccessDenied(): void
    {
        $exception = new \RuntimeException('Access denied for user');

        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($exception);

        $result = $this->service->checkMySQL();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Authentication failed', $result);
    }

    public function testCheckMySQLFailureUnknownDatabase(): void
    {
        $exception = new \RuntimeException('Unknown database');

        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($exception);

        $result = $this->service->checkMySQL();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Database not found', $result);
    }

    public function testCheckMySQLFailureConnectionRefused(): void
    {
        $exception = new \RuntimeException('Connection refused');

        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($exception);

        $result = $this->service->checkMySQL();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Cannot connect to database server', $result);
    }

    public function testCheckMySQLFailureConnectionTimeout(): void
    {
        $exception = new \RuntimeException('Connection timed out');

        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($exception);

        $result = $this->service->checkMySQL();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Connection to database server timed out', $result);
    }

    public function testCheckMySQLFailureHostnameResolution(): void
    {
        $exception = new \RuntimeException('getaddrinfo failed');

        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($exception);

        $result = $this->service->checkMySQL();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Cannot resolve database hostname', $result);
    }

    public function testCheckMySQLFailureGeneric(): void
    {
        $exception = new \RuntimeException('Some generic error: detailed message');

        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($exception);

        $result = $this->service->checkMySQL();

        $this->assertNotNull($result);
        $this->assertEquals('detailed message', $result);
    }

    public function testCheckRedisSuccess(): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willReturn('PONG');

        $result = $this->service->checkRedis();

        $this->assertNull($result);
    }

    public function testCheckRedisFailureConnectionRefused(): void
    {
        $exception = new \RuntimeException('Connection refused');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException($exception);

        $result = $this->service->checkRedis();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Cannot connect to Redis server', $result);
    }

    public function testCheckRedisFailureConnectionTimeout(): void
    {
        $exception = new \RuntimeException('Connection timed out');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException($exception);

        $result = $this->service->checkRedis();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Connection to Redis server timed out', $result);
    }

    public function testCheckRedisFailureAuthentication(): void
    {
        $exception = new \RuntimeException('AUTH failed');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException($exception);

        $result = $this->service->checkRedis();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Redis authentication failed', $result);
    }

    public function testCheckRedisFailureHostnameResolution(): void
    {
        $exception = new \RuntimeException('getaddrinfo failed');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException($exception);

        $result = $this->service->checkRedis();

        $this->assertNotNull($result);
        $this->assertStringContainsString('Cannot resolve Redis hostname', $result);
    }

    public function testCheckRedisFailureGeneric(): void
    {
        $exception = new \RuntimeException('Some generic error: detailed message');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException($exception);

        $result = $this->service->checkRedis();

        $this->assertNotNull($result);
        $this->assertEquals('detailed message', $result);
    }

    public function testCheckAllBothSuccess(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willReturn($this->createMock(Result::class));

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willReturn('PONG');

        $result = $this->service->checkAll();

        $this->assertNull($result['mysql']);
        $this->assertNull($result['redis']);
    }

    public function testCheckAllBothFailure(): void
    {
        $mysqlException = new \RuntimeException('Connection refused');

        $this->connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1')
            ->willThrowException($mysqlException);

        $redisException = new \RuntimeException('Connection refused');

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException($redisException);

        $result = $this->service->checkAll();

        $this->assertNotNull($result['mysql']);
        $this->assertNotNull($result['redis']);
        $this->assertStringContainsString('Cannot connect to database server', $result['mysql']);
        $this->assertStringContainsString('Cannot connect to Redis server', $result['redis']);
    }
}
