<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service;

use App\Service\ServiceAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ServiceAddressTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function addresses(): iterable
    {
        yield 'host and port' => ['mda:31993', 'mda', 31993];
        yield 'host only' => ['mda', 'mda', 993];
        yield 'IPv6 and port' => ['[::1]:31993', '[::1]', 31993];
        yield 'IPv6 only' => ['[::1]', '[::1]', 993];
    }

    #[DataProvider('addresses')]
    public function testParses(string $address, string $host, int $port): void
    {
        $parsed = ServiceAddress::parse($address, 993);

        self::assertSame($host, $parsed->host);
        self::assertSame($port, $parsed->port);
    }
}
