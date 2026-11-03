<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\ApiToken;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Whose API tokens an actor may see and revoke. Tokens are only ever issued to the actor themselves.
 */
final readonly class ApiTokenPolicy
{
    /**
     * Expects the query's root entity to be ApiToken.
     */
    public function restrictToVisibleTokens(QueryBuilder $qb, Actor $actor): void
    {
        $alias = $qb->getRootAliases()[0];

        match (OwnerScope::of($actor)) {
            OwnerScope::Anyone => null,
            OwnerScope::OwnDomain => $qb
                ->join(\sprintf('%s.owner', $alias), 'token_owner')
                ->andWhere('token_owner.domain = :token_domain')
                ->setParameter('token_domain', $actor->ownDomain()),
            OwnerScope::OnlyThemselves => $qb
                ->andWhere(\sprintf('%s.owner = :token_owner', $alias))
                ->setParameter('token_owner', $actor->user),
        };
    }

    public function canSeeOwners(Actor $actor): bool
    {
        return OwnerScope::OnlyThemselves !== OwnerScope::of($actor);
    }

    public function canRevoke(Actor $actor, ApiToken $token): bool
    {
        $owner = $token->getOwner();

        return match (OwnerScope::of($actor)) {
            OwnerScope::Anyone => true,
            OwnerScope::OwnDomain => $actor->managesDomain($owner->getDomain()),
            OwnerScope::OnlyThemselves => $actor->isSelf($owner),
        };
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanRevoke(Actor $actor, ApiToken $token): void
    {
        if (!$this->canRevoke($actor, $token)) {
            throw new AccessDeniedException('This token cannot be revoked.');
        }
    }
}
