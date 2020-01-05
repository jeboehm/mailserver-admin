<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Security\Provider;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class UserProvider implements UserProviderInterface
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function refreshUser(UserInterface $user): User
    {
        if (!($user instanceof User)) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        return $this->loadUserByUsername((string) $user);
    }

    public function loadUserByUsername($username): User
    {
        $user = $this->userRepository->findOneByEmailAddress((string) $username);

        if (!$user || '' === $user->getPassword()) {
            throw new UsernameNotFoundException(sprintf('Address "%s" not found or not permitted.', $username));
        }

        if ($user->isAdmin()) {
            $user->addRole('ROLE_ADMIN');
        }

        return $user;
    }

    public function supportsClass($class): bool
    {
        return User::class === $class;
    }
}
