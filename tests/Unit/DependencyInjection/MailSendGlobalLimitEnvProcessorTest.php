<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\DependencyInjection;

use App\DependencyInjection\MailSendGlobalLimitEnvProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

class MailSendGlobalLimitEnvProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function limits(): iterable
    {
        yield 'the default' => ['15', 5, '6 seconds'];
        yield 'the most Postfix allows for' => ['19', 7, '5 seconds'];
        yield 'a small one' => ['3', 1, '30 seconds'];
        yield 'one' => ['1', 1, '60 seconds'];
        yield 'one that rounds up' => ['10', 4, '10 seconds'];
    }

    #[DataProvider('limits')]
    public function testItDerivesTheBucket(string $limit, int $size, string $refill): void
    {
        $processor = new MailSendGlobalLimitEnvProcessor();
        $getEnv = static fn (): string => $limit;

        self::assertSame($size, $processor->getEnv('send_bucket_size', 'LIMIT', $getEnv));
        self::assertSame($refill, $processor->getEnv('send_bucket_refill', 'LIMIT', $getEnv));
    }

    public function testItRefusesAnythingButAnInteger(): void
    {
        $this->expectException(RuntimeException::class);

        new MailSendGlobalLimitEnvProcessor()->getEnv('send_bucket_size', 'LIMIT', static fn (): string => 'many');
    }

    public function testItProvidesBothPrefixes(): void
    {
        self::assertSame(['send_bucket_size', 'send_bucket_refill'], array_keys(MailSendGlobalLimitEnvProcessor::getProvidedTypes()));
    }
}
