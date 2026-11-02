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
use App\Service\Security\Roles;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Builds the Actor from the security token, which works for any firewall.
 */
final readonly class CurrentActor
{
    public function __construct(private Security $security)
    {
    }

    public function get(): Actor
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new \LogicException('User is not an instance of User');
        }

        return new Actor($user, $this->role());
    }

    private function role(): ActorRole
    {
        if ($this->security->isGranted(Roles::ROLE_ADMIN)) {
            return ActorRole::Admin;
        }

        if ($this->security->isGranted(Roles::ROLE_DOMAIN_ADMIN)) {
            return ActorRole::DomainAdmin;
        }

        return ActorRole::User;
    }
}
