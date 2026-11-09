<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailDraft;

use App\Exception\MailDraft\MailDraftRateLimitedException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Counts every draft against its token. There is no limit per user or overall, because Postfix is
 * not involved. It shares the pool of the send limits under an id of its own.
 */
final readonly class MailDraftRateLimiter
{
    private RateLimiterFactory $perToken;

    public function __construct(
        #[Autowire(service: 'app.mail_send_limit_pool')]
        CacheItemPoolInterface $pool,
        #[Autowire(service: 'app.mail_send_lock_factory')]
        ?LockFactory $locks,
        #[Autowire('%env(int:MCP_MAIL_DRAFT_TOKEN_LIMIT_PER_HOUR)%')]
        int $tokenLimitPerHour,
    ) {
        $this->perToken = new RateLimiterFactory(
            ['id' => 'draft_token', 'policy' => 'sliding_window', 'limit' => $tokenLimitPerHour, 'interval' => '1 hour'],
            new CacheStorage($pool),
            $locks,
        );
    }

    /**
     * @throws MailDraftRateLimitedException
     */
    public function consume(int $tokenId): void
    {
        $limit = $this->perToken->create((string) $tokenId)->consume();

        if (!$limit->isAccepted()) {
            throw new MailDraftRateLimitedException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}
