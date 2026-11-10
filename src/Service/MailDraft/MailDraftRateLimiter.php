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
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Counts every draft against its token. There is no limit per user or overall, because Postfix is
 * not involved. The limiter is `draft_token` in `framework.rate_limiter`, which shares the pool of the
 * send limits.
 */
final readonly class MailDraftRateLimiter
{
    public function __construct(
        #[Target('draft_token.limiter')]
        private RateLimiterFactoryInterface $perToken,
    ) {
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
