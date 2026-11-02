<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\Domain;
use App\Entity\User;
use App\Exception\DomainAdminWithoutDomainException;

/**
 * The authenticated user an access rule is evaluated for.
 *
 * The role is held separately from the entity on purpose. A form bound to the
 * actor's own User entity changes its admin flags before the rules run, so reading
 * them from the entity would let a user decide their own role.
 */
final readonly class Actor
{
    public function __construct(
        public User $user,
        public ActorRole $role,
    ) {
    }

    public function isAdmin(): bool
    {
        return ActorRole::Admin === $this->role;
    }

    public function isDomainAdmin(): bool
    {
        return ActorRole::DomainAdmin === $this->role;
    }

    public function isSelf(User $user): bool
    {
        return $this->user === $user
            || (null !== $user->getId() && $user->getId() === $this->user->getId());
    }

    public function managesDomain(?Domain $domain): bool
    {
        return match ($this->role) {
            ActorRole::Admin => true,
            ActorRole::DomainAdmin => null !== $domain && $this->ownsDomain($domain),
            ActorRole::User => false,
        };
    }

    /**
     * For rules that scope a domain admin to their domain, which they must have.
     *
     * @throws DomainAdminWithoutDomainException
     */
    public function ownDomain(): Domain
    {
        return $this->user->getDomain() ?? throw new DomainAdminWithoutDomainException();
    }

    private function ownsDomain(Domain $domain): bool
    {
        $own = $this->user->getDomain();

        return $own === $domain
            || (null !== $own && null !== $domain->getId() && $domain->getId() === $own->getId());
    }
}
