<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\ApiToken;

use App\Service\ApiToken\SenderBinding;
use App\Service\ApiToken\SenderMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SenderBindingTest extends TestCase
{
    public function testAFixedSenderKeepsItsAddressInLowerCase(): void
    {
        $sender = new SenderBinding(SenderMode::Fixed, 'Info@Example.com');

        self::assertTrue($sender->isFixed());
        self::assertSame('info@example.com', $sender->address);
    }

    public function testAnyAddressNeedsNoAddress(): void
    {
        $sender = new SenderBinding(SenderMode::Any);

        self::assertFalse($sender->isFixed());
        self::assertNull($sender->address);
    }

    /**
     * @return iterable<string, array{SenderMode, string|null}>
     */
    public static function inconsistentSenders(): iterable
    {
        yield 'fixed without an address' => [SenderMode::Fixed, null];
        yield 'fixed with an empty address' => [SenderMode::Fixed, ''];
        yield 'primary with an address' => [SenderMode::Primary, 'info@example.com'];
        yield 'any with an address' => [SenderMode::Any, 'info@example.com'];
    }

    #[DataProvider('inconsistentSenders')]
    public function testRefusesAnAddressThatDoesNotFitTheMode(SenderMode $mode, ?string $address): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SenderBinding($mode, $address);
    }

    /**
     * @return iterable<string, array{SenderMode, SenderBinding}>
     */
    public static function choices(): iterable
    {
        yield 'primary drops the address' => [SenderMode::Primary, new SenderBinding(SenderMode::Primary)];
        yield 'any drops the address' => [SenderMode::Any, new SenderBinding(SenderMode::Any)];
        yield 'fixed keeps it' => [SenderMode::Fixed, new SenderBinding(SenderMode::Fixed, 'info@example.com')];
    }

    #[DataProvider('choices')]
    public function testAChoiceKeepsTheAddressForAFixedSenderOnly(SenderMode $mode, SenderBinding $expected): void
    {
        self::assertEquals($expected, SenderBinding::chosen($mode, 'info@example.com'));
    }
}
