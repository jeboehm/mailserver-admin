<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

/**
 * Rules for entities that belong directly to a domain: users and aliases.
 */
final readonly class DomainScopePolicy
{
    /**
     * Expects the query's root entity to have a "domain" association.
     */
    public function restrictToVisibleDomains(QueryBuilder $qb, Actor $actor): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        if (!$actor->isDomainAdmin()) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb
            ->andWhere(\sprintf('%s.domain = :domain', $qb->getRootAliases()[0]))
            ->setParameter('domain', $actor->ownDomain());
    }

    public function canManage(Actor $actor, Alias|User $entity): bool
    {
        return $actor->managesDomain($entity->getDomain());
    }

    public function canChooseDomain(Actor $actor): bool
    {
        return $actor->isAdmin();
    }

    public function defaultDomain(Actor $actor): ?Domain
    {
        return $actor->user->getDomain();
    }

    public function applyDefaultDomain(Actor $actor, Alias|User $entity): void
    {
        $domain = $this->defaultDomain($actor);

        if (null !== $domain) {
            $entity->setDomain($domain);
        }
    }
}
