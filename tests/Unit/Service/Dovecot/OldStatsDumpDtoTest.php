<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Dovecot;

use App\Service\Dovecot\DTO\OldStatsDumpDto;
use PHPUnit\Framework\TestCase;

class OldStatsDumpDtoTest extends TestCase
{
    public function testGetCounterReturnsValue(): void
    {
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: [
                'num_logins' => 42,
                'user_cpu' => 123.456,
            ],
        );

        self::assertSame(42, $dto->getCounter('num_logins'));
        self::assertSame(123.456, $dto->getCounter('user_cpu'));
    }

    public function testGetCounterReturnsNullForMissing(): void
    {
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: [],
        );

        self::assertNull($dto->getCounter('missing'));
    }

    public function testGetCounterAsInt(): void
    {
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: [
                'num_logins' => 42,
                'float_value' => 123.456,
            ],
        );

        self::assertSame(42, $dto->getCounterAsInt('num_logins'));
        self::assertSame(123, $dto->getCounterAsInt('float_value'));
        self::assertNull($dto->getCounterAsInt('missing'));
    }

    public function testGetCounterAsFloat(): void
    {
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: [
                'num_logins' => 42,
                'user_cpu' => 123.456,
            ],
        );

        self::assertSame(42.0, $dto->getCounterAsFloat('num_logins'));
        self::assertSame(123.456, $dto->getCounterAsFloat('user_cpu'));
        self::assertNull($dto->getCounterAsFloat('missing'));
    }

    public function testHasCounter(): void
    {
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: ['num_logins' => 42],
        );

        self::assertTrue($dto->hasCounter('num_logins'));
        self::assertFalse($dto->hasCounter('missing'));
    }

    public function testGetResetDateTime(): void
    {
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: 1609459200, // 2021-01-01 00:00:00 UTC
            counters: [],
        );

        $resetDateTime = $dto->getResetDateTime();

        self::assertNotNull($resetDateTime);
        self::assertSame(1609459200, $resetDateTime->getTimestamp());
    }

    public function testGetResetDateTimeReturnsNullWhenNotSet(): void
    {
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable(),
            lastUpdateSeconds: null,
            resetTimestamp: null,
            counters: [],
        );

        self::assertNull($dto->getResetDateTime());
    }

    public function testToArray(): void
    {
        $fetchedAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $dto = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: $fetchedAt,
            lastUpdateSeconds: 1704106800.123,
            resetTimestamp: 1704103200,
            counters: [
                'num_logins' => 42,
                'user_cpu' => 123.456,
            ],
        );

        $array = $dto->toArray();

        self::assertSame('global', $array['type']);
        self::assertSame($fetchedAt->format(\DateTimeInterface::ATOM), $array['fetchedAt']);
        self::assertSame(1704106800.123, $array['lastUpdateSeconds']);
        self::assertSame(1704103200, $array['resetTimestamp']);
        self::assertSame(['num_logins' => 42, 'user_cpu' => 123.456], $array['counters']);
    }

    public function testFromArray(): void
    {
        $data = [
            'type' => 'global',
            'fetchedAt' => '2024-01-01T10:00:00+00:00',
            'lastUpdateSeconds' => 1704106800.123,
            'resetTimestamp' => 1704103200,
            'counters' => [
                'num_logins' => 42,
                'user_cpu' => 123.456,
            ],
        ];

        $dto = OldStatsDumpDto::fromArray($data);

        self::assertSame('global', $dto->type);
        self::assertSame('2024-01-01T10:00:00+00:00', $dto->fetchedAt->format(\DateTimeInterface::ATOM));
        self::assertSame(1704106800.123, $dto->lastUpdateSeconds);
        self::assertSame(1704103200, $dto->resetTimestamp);
        self::assertSame(42, $dto->getCounter('num_logins'));
        self::assertSame(123.456, $dto->getCounter('user_cpu'));
    }

    public function testRoundTrip(): void
    {
        $original = new OldStatsDumpDto(
            type: 'global',
            fetchedAt: new \DateTimeImmutable('2024-01-01 10:00:00'),
            lastUpdateSeconds: 1704106800.123,
            resetTimestamp: 1704103200,
            counters: [
                'num_logins' => 42,
                'auth_successes' => 100,
                'user_cpu' => 123.456,
            ],
        );

        $array = $original->toArray();
        $restored = OldStatsDumpDto::fromArray($array);

        self::assertSame($original->type, $restored->type);
        self::assertSame($original->lastUpdateSeconds, $restored->lastUpdateSeconds);
        self::assertSame($original->resetTimestamp, $restored->resetTimestamp);
        self::assertEquals($original->counters, $restored->counters);
    }
}
