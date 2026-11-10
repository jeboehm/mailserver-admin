<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\MailSend;

use App\Exception\MailSend\MailSendRateLimitedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\ClockMock;
use Tests\Unit\Helper\Limiters;

#[Group('time-sensitive')]
class MailSendRateLimiterTest extends TestCase
{
    private const int PLENTY = 1000000;

    #[\Override]
    protected function setUp(): void
    {
        ClockMock::withClockMock(strtotime('2026-11-03 12:00:00'));
    }

    #[\Override]
    protected function tearDown(): void
    {
        ClockMock::withClockMock(false);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function globalLimits(): iterable
    {
        yield 'the default' => [15];
        yield 'the most Postfix allows for' => [19];
        yield 'a small one' => [3];
    }

    /**
     * Postfix counts per rolling minute, so no 60 seconds may hold more than the limit, not even for
     * someone who keeps trying once a second.
     */
    #[DataProvider('globalLimits')]
    public function testNoRollingMinuteExceedsTheGlobalLimit(int $limit): void
    {
        $limiter = Limiters::send(self::PLENTY, self::PLENTY, $limit);
        $accepted = [];

        for ($second = 0; $second < 600; ++$second) {
            try {
                $limiter->consume($second, $second);
                $accepted[] = $second;
            } catch (MailSendRateLimitedException) {
            }

            ClockMock::sleep(1);
        }

        foreach ($accepted as $start) {
            $inWindow = array_filter($accepted, static fn (int $second): bool => $second >= $start && $second < $start + 60);
            self::assertLessThanOrEqual($limit, \count($inWindow), \sprintf('The minute from second %d.', $start));
        }

        self::assertGreaterThan((int) ($limit / 2) * 9, \count($accepted), 'Ten minutes should get most of what the limit allows.');
    }

    public function testARefusalSaysWhenToRetry(): void
    {
        $limiter = Limiters::send(1, self::PLENTY, self::PLENTY);
        $limiter->consume(1, 1);

        $this->expectException(MailSendRateLimitedException::class);
        $this->expectExceptionMessageMatches('/Retry in [1-9]\d* seconds/');

        $limiter->consume(1, 1);
    }

    public function testARefusalByTheTokenLimitLeavesTheGlobalLimitAlone(): void
    {
        $limiter = Limiters::send(1, self::PLENTY, 3);
        $limiter->consume(1, 1);

        for ($i = 0; $i < 5; ++$i) {
            try {
                $limiter->consume(1, 1);
                self::fail('The token is over its limit.');
            } catch (MailSendRateLimitedException) {
            }
        }

        ClockMock::sleep(30);

        $limiter->consume(2, 2);
        $this->addToAssertionCount(1);
    }
}
