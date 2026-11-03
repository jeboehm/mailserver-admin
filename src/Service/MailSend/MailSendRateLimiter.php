<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use App\Exception\MailSend\MailSendRateLimitedException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Counts every send against its token, its user and all of mailserver-admin.
 *
 * The global limit protects against Postfix's 20 submission connections per minute per client
 * address, which all tokens and Roundcube share. Postfix counts per rolling minute, so the policy has
 * to hold for any 60 seconds: a fixed or sliding window lets through up to twice its limit around a
 * window's end. A token bucket cannot exceed its size plus what it refills during that time, so a
 * third of the limit is the size and the rest the refill.
 */
final readonly class MailSendRateLimiter
{
    private RateLimiterFactory $perToken;
    private RateLimiterFactory $perUser;
    private RateLimiterFactory $global;

    public function __construct(
        #[Autowire(service: 'app.mail_send_limit_pool')]
        CacheItemPoolInterface $pool,
        #[Autowire(service: 'app.mail_send_lock_factory')]
        ?LockFactory $locks,
        #[Autowire('%env(int:MCP_MAIL_SEND_TOKEN_LIMIT_PER_HOUR)%')]
        int $tokenLimitPerHour,
        #[Autowire('%env(int:MCP_MAIL_SEND_USER_LIMIT_PER_HOUR)%')]
        int $userLimitPerHour,
        #[Autowire('%env(int:MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE)%')]
        int $globalLimitPerMinute,
    ) {
        $storage = new CacheStorage($pool);
        $size = (int) ceil($globalLimitPerMinute / 3);
        $refillPerMinute = max(1, $globalLimitPerMinute - $size);

        $this->perToken = new RateLimiterFactory(
            ['id' => 'token', 'policy' => 'sliding_window', 'limit' => $tokenLimitPerHour, 'interval' => '1 hour'],
            $storage,
            $locks,
        );
        $this->perUser = new RateLimiterFactory(
            ['id' => 'user', 'policy' => 'sliding_window', 'limit' => $userLimitPerHour, 'interval' => '1 hour'],
            $storage,
            $locks,
        );
        $this->global = new RateLimiterFactory(
            [
                'id' => 'global',
                'policy' => 'token_bucket',
                'limit' => $size,
                'rate' => ['interval' => \sprintf('%d seconds', ceil(60 / $refillPerMinute)), 'amount' => 1],
            ],
            $storage,
            $locks,
        );
    }

    /**
     * Narrowest first, so one busy token runs into its own limit and leaves the global one alone.
     *
     * @throws MailSendRateLimitedException
     */
    public function consume(int $tokenId, int $userId): void
    {
        $this->consumeFrom($this->perToken->create((string) $tokenId));
        $this->consumeFrom($this->perUser->create((string) $userId));
        $this->consumeFrom($this->global->create('all'));
    }

    private function consumeFrom(LimiterInterface $limiter): void
    {
        $limit = $limiter->consume();

        if (!$limit->isAccepted()) {
            throw new MailSendRateLimitedException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
