<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service;

use App\Repository\UserRepository;
use App\Service\ConnectionCheckService;
use App\Service\Dovecot\DoveadmHttpClient;
use App\Service\Dovecot\DTO\DoveadmHealthDto;
use App\Service\Rspamd\DTO\HealthDto;
use App\Service\Rspamd\RspamdControllerClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

#[AllowMockObjectsWithoutExpectations]
class ConnectionCheckServiceTest extends TestCase
{
    private MockObject|UserRepository $userRepository;
    private MockObject|ClientInterface $redis;
    private MockObject|DoveadmHttpClient $doveadmHttpClient;
    private MockObject|RspamdControllerClient $rspamdControllerClient;
    private ConnectionCheckService $service;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->redis = $this->createMock(ClientInterface::class);
        $this->doveadmHttpClient = $this->createMock(DoveadmHttpClient::class);
        $this->rspamdControllerClient = $this->createMock(RspamdControllerClient::class);

        $this->service = new ConnectionCheckService(
            $this->userRepository,
            $this->redis,
            $this->doveadmHttpClient,
            $this->rspamdControllerClient
        );
    }

    #[DataProvider('mysqlErrorProvider')]
    public function testCheckMySQLErrors(string $errorMessage, string $expectedContain): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findBy')
            ->with([], null, 1)
            ->willThrowException(new \RuntimeException($errorMessage));

        $result = $this->service->checkMySQL();

        $this->assertNotNull($result);
        $this->assertStringContainsString($expectedContain, $result);
    }

    public static function mysqlErrorProvider(): array
    {
        return [
            'access denied' => ['Access denied for user', 'Authentication failed'],
            'unknown database' => ['Unknown database', 'Database not found'],
            'connection refused' => ['Connection refused', 'Cannot connect to database server'],
            'no connection' => ['No connection', 'Cannot connect to database server'],
            'connection timeout' => ['Connection timed out', 'Connection to database server timed out'],
            'hostname resolution' => ['getaddrinfo failed', 'Cannot resolve database hostname'],
            'hostname translation' => ['could not translate host name', 'Cannot resolve database hostname'],
            'generic error' => ['Error: detailed message', 'detailed message'],
        ];
    }

    public function testCheckMySQLSuccess(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findBy')
            ->with([], null, 1)
            ->willReturn([]);

        $result = $this->service->checkMySQL();

        $this->assertNull($result);
    }

    #[DataProvider('redisErrorProvider')]
    public function testCheckRedisErrors(string $errorMessage, string $expectedContain): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException(new \RuntimeException($errorMessage));

        $result = $this->service->checkRedis();

        $this->assertNotNull($result);
        $this->assertStringContainsString($expectedContain, $result);
    }

    public static function redisErrorProvider(): array
    {
        return [
            'connection refused' => ['Connection refused', 'Cannot connect to Redis server'],
            'no connection' => ['No connection', 'Cannot connect to Redis server'],
            'connection timeout' => ['Connection timed out', 'Connection to Redis server timed out'],
            'authentication failed' => ['AUTH failed', 'Redis authentication failed'],
            'authentication lowercase' => ['authentication failed', 'Redis authentication failed'],
            'hostname resolution' => ['getaddrinfo failed', 'Cannot resolve Redis hostname'],
            'hostname translation' => ['could not translate host name', 'Cannot resolve Redis hostname'],
            'generic error' => ['Error: detailed message', 'detailed message'],
        ];
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

    public function testCheckDoveadmSuccess(): void
    {
        $healthDto = DoveadmHealthDto::ok(new \DateTimeImmutable());

        $this->doveadmHttpClient
            ->expects($this->once())
            ->method('checkHealth')
            ->willReturn($healthDto);

        $result = $this->service->checkDoveadm();

        $this->assertNull($result);
    }

    #[DataProvider('doveadmErrorProvider')]
    public function testCheckDoveadmErrors(string $factoryMethod, string $expectedMessage): void
    {
        $healthDto = match ($factoryMethod) {
            'connectionError' => DoveadmHealthDto::connectionError('Connection failed'),
            'authenticationError' => DoveadmHealthDto::authenticationError(),
            'formatError' => DoveadmHealthDto::formatError('Invalid response'),
            'notConfigured' => DoveadmHealthDto::notConfigured(),
            default => throw new \InvalidArgumentException("Unknown factory method: {$factoryMethod}"),
        };

        $this->doveadmHttpClient
            ->expects($this->once())
            ->method('checkHealth')
            ->willReturn($healthDto);

        $result = $this->service->checkDoveadm();

        $this->assertNotNull($result);
        $this->assertStringContainsString($expectedMessage, $result);
    }

    public static function doveadmErrorProvider(): array
    {
        return [
            'connection error' => ['connectionError', 'Cannot connect to Doveadm API'],
            'authentication error' => ['authenticationError', 'Authentication failed'],
            'format error' => ['formatError', 'Unexpected response format'],
            'not configured' => ['notConfigured', 'Doveadm HTTP API is not configured'],
        ];
    }

    public function testCheckRspamdSuccess(): void
    {
        $healthDto = HealthDto::ok('Rspamd is healthy');

        $this->rspamdControllerClient
            ->expects($this->once())
            ->method('ping')
            ->willReturn($healthDto);

        $result = $this->service->checkRspamd();

        $this->assertNull($result);
    }

    #[DataProvider('rspamdErrorProvider')]
    public function testCheckRspamdErrors(string $factoryMethod, string $expectedMessage): void
    {
        $healthDto = match ($factoryMethod) {
            'warning' => HealthDto::warning('Unexpected ping response', 500),
            'critical' => HealthDto::critical('Connection timeout'),
            default => throw new \InvalidArgumentException("Unknown factory method: {$factoryMethod}"),
        };

        $this->rspamdControllerClient
            ->expects($this->once())
            ->method('ping')
            ->willReturn($healthDto);

        $result = $this->service->checkRspamd();

        $this->assertNotNull($result);
        $this->assertStringContainsString($expectedMessage, $result);
    }

    public static function rspamdErrorProvider(): array
    {
        return [
            'warning' => ['warning', 'Unexpected ping response'],
            'critical' => ['critical', 'Connection timeout'],
        ];
    }

    public function testCheckAllBasic(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findBy')
            ->with([], null, 1)
            ->willReturn([]);

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willReturn('PONG');

        $this->doveadmHttpClient->expects($this->never())->method('checkHealth');
        $this->rspamdControllerClient->expects($this->never())->method('ping');

        $result = $this->service->checkAll();

        $this->assertArrayHasKey('mysql', $result);
        $this->assertArrayHasKey('redis', $result);
        $this->assertArrayNotHasKey('doveadm', $result);
        $this->assertArrayNotHasKey('rspamd', $result);
        $this->assertNull($result['mysql']);
        $this->assertNull($result['redis']);
    }

    public function testCheckAllWithAllServices(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findBy')
            ->with([], null, 1)
            ->willReturn([]);

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willReturn('PONG');

        $this->doveadmHttpClient
            ->expects($this->once())
            ->method('checkHealth')
            ->willReturn(DoveadmHealthDto::ok(new \DateTimeImmutable()));

        $this->rspamdControllerClient
            ->expects($this->once())
            ->method('ping')
            ->willReturn(HealthDto::ok('Rspamd is healthy'));

        $result = $this->service->checkAll(true);

        $this->assertArrayHasKey('mysql', $result);
        $this->assertArrayHasKey('redis', $result);
        $this->assertArrayHasKey('doveadm', $result);
        $this->assertArrayHasKey('rspamd', $result);
        $this->assertNull($result['mysql']);
        $this->assertNull($result['redis']);
        if (isset($result['doveadm'])) {
            $this->assertNull($result['doveadm']);
        }
        if (isset($result['rspamd'])) {
            $this->assertNull($result['rspamd']);
        }
    }

    public function testCheckAllWithFailures(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findBy')
            ->with([], null, 1)
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->service->checkAll();

        $this->assertNotNull($result['mysql']);
        $this->assertNotNull($result['redis']);
        $this->assertStringContainsString('Cannot connect to database server', $result['mysql']);
        $this->assertStringContainsString('Cannot connect to Redis server', $result['redis']);
    }

    public function testCheckAllWithAllServicesAndFailures(): void
    {
        $this->userRepository
            ->expects($this->once())
            ->method('findBy')
            ->with([], null, 1)
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with('ping', [])
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->doveadmHttpClient
            ->expects($this->once())
            ->method('checkHealth')
            ->willReturn(DoveadmHealthDto::connectionError('Connection failed'));

        $this->rspamdControllerClient
            ->expects($this->once())
            ->method('ping')
            ->willReturn(HealthDto::critical('Connection timeout'));

        $result = $this->service->checkAll(true);

        $this->assertNotNull($result['mysql']);
        $this->assertNotNull($result['redis']);
        $this->assertStringContainsString('Cannot connect to database server', $result['mysql']);
        $this->assertStringContainsString('Cannot connect to Redis server', $result['redis']);
        if (isset($result['doveadm'])) {
            $this->assertNotNull($result['doveadm']);
            $this->assertStringContainsString('Cannot connect to Doveadm API', $result['doveadm']);
        }
        if (isset($result['rspamd'])) {
            $this->assertNotNull($result['rspamd']);
            $this->assertStringContainsString('Connection timeout', $result['rspamd']);
        }
    }
}
