<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class UserPolicy
{
    public function canSetAdminFlag(Actor $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Call after the new values were applied to the entity and before flushing.
     */
    public function keepOwnRoleAndEnabledState(Actor $actor, User $user): void
    {
        if (!$actor->isSelf($user)) {
            return;
        }

        $user->setEnabled(true);
        $user->setAdmin($actor->isAdmin());
        $user->setDomainAdmin($actor->isDomainAdmin());
    }

    /**
     * A domain admin may not change an admin, even in their own domain: a password reset would take
     * over the account. Call this before the new values are applied, as it reads the admin flag.
     */
    public function canModify(Actor $actor, User $user): bool
    {
        return $actor->managesDomain($user->getDomain()) && ($actor->isAdmin() || !$user->isAdmin());
    }

    public function canDelete(Actor $actor, User $user): bool
    {
        return !$actor->isSelf($user) && $this->canModify($actor, $user);
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanDelete(Actor $actor, User $user): void
    {
        if (!$this->canDelete($actor, $user)) {
            throw new AccessDeniedException('This user cannot be deleted.');
        }
    }
}
