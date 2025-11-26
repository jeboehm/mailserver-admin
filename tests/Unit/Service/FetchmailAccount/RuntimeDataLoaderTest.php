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
use Predis\Client;
use Symfony\Component\Serializer\SerializerInterface;

class RuntimeDataLoaderTest extends TestCase
{
    private MockObject&Client $redis;
    private MockObject&SerializerInterface $serializer;
    private RuntimeDataLoader $loader;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(Client::class);
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

    public function testPostLoadWithValidData(): void
    {
        $fetchmailAccount = $this->createPartialMock(FetchmailAccount::class, ['getId']);
        $fetchmailAccount->expects($this->once())
            ->method('getId')
            ->willReturn(123);

        $json = '{"isSuccess": true}';
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
}
