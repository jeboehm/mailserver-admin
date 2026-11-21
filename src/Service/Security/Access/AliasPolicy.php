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
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AliasPolicy
{
    /**
     * Without a repository, no address counts as an admin's.
     */
    public function __construct(private ?UserRepository $users = null)
    {
    }

    /**
     * A domain admin may not use an admin's address as an alias source, as that diverts the admin's mail.
     * This reads the values an alias has now, so call it before new values are applied to a stored alias.
     */
    public function canUseAddress(Actor $actor, ?Domain $domain, string $name): bool
    {
        return $actor->managesDomain($domain)
            && ($actor->isAdmin() || !$this->users?->isAdminAddress($name, $domain));
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanUseAddress(Actor $actor, ?Domain $domain, string $name): void
    {
        if (!$this->canUseAddress($actor, $domain, $name)) {
            throw new AccessDeniedException('This alias address cannot be used.');
        }
    }

    public function canModify(Actor $actor, Alias $alias): bool
    {
        return $this->canUseAddress($actor, $alias->getDomain(), $alias->getName());
    }

    public function canDelete(Actor $actor, Alias $alias): bool
    {
        return $this->canModify($actor, $alias);
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanDelete(Actor $actor, Alias $alias): void
    {
        if (!$this->canDelete($actor, $alias)) {
            throw new AccessDeniedException('This alias cannot be deleted.');
        }
    }
}
