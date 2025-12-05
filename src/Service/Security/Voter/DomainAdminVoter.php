<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Voter;

use App\Entity\Alias;
use App\Entity\User;
use App\Service\Security\Roles;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class DomainAdminVoter extends Voter
{
    public const string VIEW = 'domain_related_entity_view';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute
            && ($subject instanceof User || $subject instanceof Alias || null === $subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (null === $subject) {
            return true;
        }

        assert($subject instanceof User || $subject instanceof Alias);

        if (in_array(Roles::ROLE_ADMIN, $token->getRoleNames(), true)) {
            return true;
        }

        if (in_array(Roles::ROLE_DOMAIN_ADMIN, $token->getRoleNames(), true)) {
            $user = $token->getUser();

            if (!($user instanceof User)) {
                throw new \LogicException('User is not an instance of User');
            }

            return $subject->getDomain() === $user->getDomain();
        }

        return false;
    }
}
