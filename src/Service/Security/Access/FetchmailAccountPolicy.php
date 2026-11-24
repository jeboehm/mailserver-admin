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

    /**
     * The part of canAssignTo() that restrictToAssignableOwners() leaves out: a domain admin may not
     * assign accounts to admins. Expects the query's root entity to be User.
     */
    public function excludeAdminOwners(QueryBuilder $qb, Actor $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        $qb->andWhere(\sprintf('%s.admin = false', $qb->getRootAliases()[0]));
    }

    /**
     * A domain admin may not change what an admin fetches, even in their own domain: a new host would
     * receive the password, which stays when it is left out. They can still see these accounts.
     */
    public function canAssignTo(Actor $actor, User $owner): bool
    {
        return $this->isInScope($actor, $owner) && ($actor->isAdmin() || !$owner->isAdmin());
    }

    public function canView(Actor $actor, FetchmailAccount $account): bool
    {
        $owner = $account->getUser();

        return null === $owner ? $actor->isAdmin() : $this->isInScope($actor, $owner);
    }

    public function canManage(Actor $actor, FetchmailAccount $account): bool
    {
        $owner = $account->getUser();

        return null === $owner ? $actor->isAdmin() : $this->canAssignTo($actor, $owner);
    }

    public function canDelete(Actor $actor, FetchmailAccount $account): bool
    {
        return $this->canManage($actor, $account);
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

    private function isInScope(Actor $actor, User $owner): bool
    {
        return match (OwnerScope::of($actor)) {
            OwnerScope::Anyone => true,
            OwnerScope::OwnDomain => $actor->managesDomain($owner->getDomain()),
            OwnerScope::OnlyThemselves => $actor->isSelf($owner),
        };
    }
}
