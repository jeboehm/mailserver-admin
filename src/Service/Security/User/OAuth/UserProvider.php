<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\User\OAuth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

readonly class UserProvider implements UserProviderInterface, OAuthAwareUserProviderInterface
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(string:OAUTH_ADMIN_GROUP)%')]
        private string $adminGroupName,
    ) {
    }

    public function loadUserByOAuthUserResponse(UserResponseInterface $response): User
    {
        $emailAddress = method_exists($response, 'getUserIdentifier') ? $response->getUserIdentifier() : $response->getUsername();

        if (!filter_var($emailAddress, \FILTER_VALIDATE_EMAIL)) {
            throw $this->createUserNotFoundException(username: $emailAddress, message: 'No email address found in OAuth response. Check your OAUTH_PATHS_IDENTIFIER setting.');
        }

        $user = $this->findUser($emailAddress) ?? throw $this->createUserNotFoundException($emailAddress, \sprintf("User '%s' not found.", $emailAddress));

        $this->updateUser($user, $response);

        return $user;
    }

    public function refreshUser(UserInterface $user): User
    {
        if (!($user instanceof User)) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $email = $user->getUserIdentifier();

        if (null === $user = $this->findUser($email)) {
            throw $this->createUserNotFoundException($email, \sprintf('User with ID "%s" could not be reloaded.', $email));
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return User::class === $class;
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->findUser($identifier);

        if (!$user) {
            throw $this->createUserNotFoundException($identifier, \sprintf("User '%s' not found.", $identifier));
        }

        return $user;
    }

    private function findUser(?string $emailAddress): ?User
    {
        return $this->userRepository->findOneByEmailAddress(
            \mb_strtolower($emailAddress)
        );
    }

    private function createUserNotFoundException(string $username, string $message): UserNotFoundException
    {
        $exception = new AccountNotLinkedException($message);
        $exception->setUserIdentifier($username);

        return $exception;
    }

    private function updateUser(User $user, UserResponseInterface $response): void
    {
        $flush = false;
        $isAdmin = $this->determineAdmin($response);

        if ($isAdmin !== $user->isAdmin()) {
            $user->setAdmin($isAdmin);
            $flush = true;
        }

        if (!$user->getEnabled()) {
            $user->setEnabled(true);
            $flush = true;
        }

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    private function determineAdmin(UserResponseInterface $userInformation): bool
    {
        $groups = $userInformation->getData()['groups'] ?? false;

        if (!is_array($groups)) {
            return false;
        }

        return in_array($this->adminGroupName, $groups, true);
    }
}
