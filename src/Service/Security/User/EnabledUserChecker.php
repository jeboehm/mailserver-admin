<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\User;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Rejects locally disabled users. Set it as `user_checker` on every firewall that authenticates users.
 *
 * The OAuth user provider re-enables users while it builds the passport, which happens before this
 * check, so OIDC logins of locally disabled users succeed: the identity provider is authoritative.
 */
readonly class EnabledUserChecker implements UserCheckerInterface
{
    #[\Override]
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof User && !$user->getEnabled()) {
            throw new DisabledException('User account is disabled.');
        }
    }

    #[\Override]
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
