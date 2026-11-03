<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Security;

use App\Entity\ApiToken;
use App\Mcp\CurrentApiToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\AuthenticationTokenCreatedEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Carries the API token from the badge to the security token, which the authenticator does not do,
 * and records its last use once authentication has fully succeeded.
 */
final readonly class ApiTokenAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [
            AuthenticationTokenCreatedEvent::class => 'carryApiToken',
            LoginSuccessEvent::class => 'recordUse',
        ];
    }

    public function carryApiToken(AuthenticationTokenCreatedEvent $event): void
    {
        $badge = $event->getPassport()->getBadge(UserBadge::class);
        $apiToken = $badge instanceof UserBadge ? ($badge->getAttributes()[CurrentApiToken::TOKEN_ATTRIBUTE] ?? null) : null;

        if ($apiToken instanceof ApiToken) {
            $event->getAuthenticatedToken()->setAttribute(CurrentApiToken::TOKEN_ATTRIBUTE, $apiToken);
        }
    }

    public function recordUse(LoginSuccessEvent $event): void
    {
        $token = $event->getAuthenticatedToken();

        if (!$token->hasAttribute(CurrentApiToken::TOKEN_ATTRIBUTE)) {
            return;
        }

        if ($token->getAttribute(CurrentApiToken::TOKEN_ATTRIBUTE)->markUsed(new \DateTimeImmutable())) {
            $this->entityManager->flush();
        }
    }
}
