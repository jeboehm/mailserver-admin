<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\FetchmailAccount;

use App\Entity\FetchmailAccount;
use App\Service\FetchmailAccount\RedisKeys;
use App\Service\FetchmailAccount\RuntimeData;
use App\Service\FetchmailAccount\RuntimeDataLoader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Symfony\Component\Serializer\SerializerInterface;

class RuntimeDataLoaderTest extends TestCase
{
    private MockObject&ClientInterface $redis;
    private MockObject&SerializerInterface $serializer;
    private RuntimeDataLoader $loader;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(ClientInterface::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->loader = new RuntimeDataLoader($this->redis, $this->serializer);
    }

    public function testPostLoadWithNoData(): void
    {
        $fetchmailAccount = $this->createMock(FetchmailAccount::class);
        $fetchmailAccount->expects($this->once())
            ->method('getId')
            ->willReturn(123);

        $this->redis->expects($this->once())
            ->method('__call')
            ->with('get', [RedisKeys::createRuntimeKey(123)])
            ->willReturn(null);

        $this->serializer->expects($this->never())
            ->method('deserialize');

        $this->loader->postLoad($fetchmailAccount);
    }

    public function testPostLoadWithInvalidData(): void
    {
        $fetchmailAccount = $this->createMock(FetchmailAccount::class);
        $fetchmailAccount->expects($this->once())
            ->method('getId')
            ->willReturn(123);

        $json = '{"some": "data"}';
        $this->redis->expects($this->once())
            ->method('__call')
            ->with('get', [RedisKeys::createRuntimeKey(123)])
            ->willReturn($json);

        $this->serializer->expects($this->once())
            ->method('deserialize')
            ->with($json, RuntimeData::class, 'json')
            ->willReturn(new \stdClass()); // Not a RuntimeData instance

        $this->loader->postLoad($fetchmailAccount);
    }

    public function testPostLoadWithValidDataSuccess(): void
    {
        $fetchmailAccount = new FetchmailAccount();
        $reflection = new \ReflectionClass($fetchmailAccount);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($fetchmailAccount, 123);

        $json = '{"isSuccess": true, "lastRun": "2023-01-01T10:00:00+00:00", "lastLog": "Log message"}';
        $this->redis->expects($this->once())
            ->method('__call')
            ->with('get', [RedisKeys::createRuntimeKey(123)])
            ->willReturn($json);

        $runtimeData = new RuntimeData();
        $runtimeData->isSuccess = true;
        $runtimeData->lastRun = new \DateTimeImmutable('2023-01-01 10:00:00');
        $runtimeData->lastLog = 'Log message';

        $this->serializer->expects($this->once())
            ->method('deserialize')
            ->with($json, RuntimeData::class, 'json')
            ->willReturn($runtimeData);

        $this->loader->postLoad($fetchmailAccount);

        $this->assertTrue($fetchmailAccount->isSuccess);
        $this->assertSame($runtimeData->lastRun, $fetchmailAccount->lastRun);
        $this->assertSame('Log message', $fetchmailAccount->lastLog);
    }

    public function testPostLoadWithValidDataFailure(): void
    {
        $fetchmailAccount = new FetchmailAccount();
        $reflection = new \ReflectionClass($fetchmailAccount);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($fetchmailAccount, 456);

        $json = '{"isSuccess": false, "lastRun": "2023-02-01T15:30:00+00:00", "lastLog": "Error occurred"}';
        $this->redis->expects($this->once())
            ->method('__call')
            ->with('get', [RedisKeys::createRuntimeKey(456)])
            ->willReturn($json);

        $runtimeData = new RuntimeData();
        $runtimeData->isSuccess = false;
        $runtimeData->lastRun = new \DateTimeImmutable('2023-02-01 15:30:00');
        $runtimeData->lastLog = 'Error occurred';

        $this->serializer->expects($this->once())
            ->method('deserialize')
            ->with($json, RuntimeData::class, 'json')
            ->willReturn($runtimeData);

        $this->loader->postLoad($fetchmailAccount);

        $this->assertFalse($fetchmailAccount->isSuccess);
        $this->assertSame($runtimeData->lastRun, $fetchmailAccount->lastRun);
        $this->assertSame('Error occurred', $fetchmailAccount->lastLog);
    }

    public function testPostLoadDoesNotModifyPropertiesWhenDataIsNull(): void
    {
        $fetchmailAccount = new FetchmailAccount();
        $reflection = new \ReflectionClass($fetchmailAccount);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($fetchmailAccount, 789);

        // Set initial values
        $fetchmailAccount->isSuccess = true;
        $fetchmailAccount->lastRun = new \DateTimeImmutable('2020-01-01 00:00:00');
        $fetchmailAccount->lastLog = 'Initial log';

        $this->redis->expects($this->once())
            ->method('__call')
            ->with('get', [RedisKeys::createRuntimeKey(789)])
            ->willReturn(null);

        $this->serializer->expects($this->never())
            ->method('deserialize');

        $this->loader->postLoad($fetchmailAccount);

        // Properties should remain unchanged
        $this->assertTrue($fetchmailAccount->isSuccess);
        $this->assertSame('2020-01-01 00:00:00', $fetchmailAccount->lastRun->format('Y-m-d H:i:s'));
        $this->assertSame('Initial log', $fetchmailAccount->lastLog);
    }

    public function testPostLoadDoesNotModifyPropertiesWhenDataIsInvalid(): void
    {
        $fetchmailAccount = new FetchmailAccount();
        $reflection = new \ReflectionClass($fetchmailAccount);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($fetchmailAccount, 999);

        // Set initial values
        $fetchmailAccount->isSuccess = false;
        $fetchmailAccount->lastRun = new \DateTimeImmutable('2021-01-01 00:00:00');
        $fetchmailAccount->lastLog = 'Previous log';

        $json = '{"invalid": "data"}';
        $this->redis->expects($this->once())
            ->method('__call')
            ->with('get', [RedisKeys::createRuntimeKey(999)])
            ->willReturn($json);

        $this->serializer->expects($this->once())
            ->method('deserialize')
            ->with($json, RuntimeData::class, 'json')
            ->willReturn(new \stdClass());

        $this->loader->postLoad($fetchmailAccount);

        // Properties should remain unchanged
        $this->assertFalse($fetchmailAccount->isSuccess);
        $this->assertSame('2021-01-01 00:00:00', $fetchmailAccount->lastRun->format('Y-m-d H:i:s'));
        $this->assertSame('Previous log', $fetchmailAccount->lastLog);
    }
}
