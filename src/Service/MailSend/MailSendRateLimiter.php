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
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Counts every send against its token, its user and all of mailserver-admin.
 *
 * The global limit protects against Postfix's 20 submission connections per minute per client
 * address, which all tokens and Roundcube share. Postfix counts per rolling minute, so the policy has
 * to hold for any 60 seconds: a fixed or sliding window lets through up to twice its limit around a
 * window's end. A token bucket cannot exceed its size plus what it refills during that time, so a
 * third of the limit is the size and the rest the refill.
 * `framework.rate_limiter` defines the three limiters, and `MailSendGlobalLimitEnvProcessor` derives the
 * bucket from the limit.
 */
final readonly class MailSendRateLimiter
{
    public function __construct(
        #[Target('send_token.limiter')]
        private RateLimiterFactoryInterface $perToken,
        #[Target('send_user.limiter')]
        private RateLimiterFactoryInterface $perUser,
        #[Target('send_global.limiter')]
        private RateLimiterFactoryInterface $global,
    ) {
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
