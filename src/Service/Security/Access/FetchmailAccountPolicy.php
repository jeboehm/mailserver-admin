<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\FetchmailAccount;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class FetchmailAccountPolicy
{
    /**
     * Expects the query's root entity to be FetchmailAccount.
     */
    public function restrictToVisibleAccounts(QueryBuilder $qb, Actor $actor): void
    {
        $alias = $qb->getRootAliases()[0];

        match (OwnerScope::of($actor)) {
            OwnerScope::Anyone => null,
            OwnerScope::OwnDomain => $qb
                ->andWhere('user.domain = :domain')
                ->join(\sprintf('%s.user', $alias), 'user')
                ->setParameter('domain', $actor->ownDomain()),
            OwnerScope::OnlyThemselves => $qb
                ->andWhere(\sprintf('%s.user = :user', $alias))
                ->setParameter('user', $actor->user),
        };
    }

    /**
     * Expects the query's root entity to be User.
     */
    public function restrictToAssignableOwners(QueryBuilder $qb, Actor $actor): void
    {
        $alias = $qb->getRootAliases()[0];

        match (OwnerScope::of($actor)) {
            OwnerScope::Anyone => null,
            OwnerScope::OwnDomain => $qb
                ->andWhere(\sprintf('%s.domain = :domain', $alias))
                ->setParameter('domain', $actor->ownDomain()),
            OwnerScope::OnlyThemselves => $qb
                ->andWhere(\sprintf('%s = :owner', $alias))
                ->setParameter('owner', $actor->user),
        };
    }

    public function canAssignTo(Actor $actor, User $owner): bool
    {
        return match (OwnerScope::of($actor)) {
            OwnerScope::Anyone => true,
            OwnerScope::OwnDomain => $actor->managesDomain($owner->getDomain()),
            OwnerScope::OnlyThemselves => $actor->isSelf($owner),
        };
    }

    public function canDelete(Actor $actor, FetchmailAccount $account): bool
    {
        $owner = $account->getUser();

        return null === $owner ? $actor->isAdmin() : $this->canAssignTo($actor, $owner);
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanDelete(Actor $actor, FetchmailAccount $account): void
    {
        if (!$this->canDelete($actor, $account)) {
            throw new AccessDeniedException('This fetchmail account cannot be deleted.');
        }
    }

    public function canChooseOwner(Actor $actor): bool
    {
        return $actor->isAdmin() || $actor->isDomainAdmin();
    }

    public function defaultOwner(Actor $actor): User
    {
        return $actor->user;
    }
}
