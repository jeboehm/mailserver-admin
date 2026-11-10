<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Helper;

use App\DependencyInjection\MailSendGlobalLimitEnvProcessor;
use App\Service\MailDraft\MailDraftRateLimiter;
use App\Service\MailSend\MailSendRateLimiter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Builds the limiters as `framework.rate_limiter` defines them, on a storage of their own.
 */
final class Limiters
{
    public static function send(int $tokenLimitPerHour, int $userLimitPerHour, int $globalLimitPerMinute): MailSendRateLimiter
    {
        $storage = new CacheStorage(new ArrayAdapter());
        $processor = new MailSendGlobalLimitEnvProcessor();
        $getEnv = static fn (): int => $globalLimitPerMinute;

        return new MailSendRateLimiter(
            self::window('send_token', $tokenLimitPerHour, $storage),
            self::window('send_user', $userLimitPerHour, $storage),
            new RateLimiterFactory(
                [
                    'id' => 'send_global',
                    'policy' => 'token_bucket',
                    'limit' => $processor->getEnv('send_bucket_size', 'LIMIT', $getEnv),
                    'rate' => ['interval' => $processor->getEnv('send_bucket_refill', 'LIMIT', $getEnv), 'amount' => 1],
                ],
                $storage,
            ),
        );
    }

    public static function draft(int $tokenLimitPerHour): MailDraftRateLimiter
    {
        return new MailDraftRateLimiter(self::window('draft_token', $tokenLimitPerHour, new CacheStorage(new ArrayAdapter())));
    }

    private static function window(string $id, int $limit, CacheStorage $storage): RateLimiterFactoryInterface
    {
        return new RateLimiterFactory(['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'], $storage);
    }
}
