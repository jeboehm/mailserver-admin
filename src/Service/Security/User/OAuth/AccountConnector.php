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
use Doctrine\ORM\EntityManagerInterface;
use HWI\Bundle\OAuthBundle\Connect\AccountConnectorInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use Symfony\Component\Security\Core\User\UserInterface;

readonly class AccountConnector implements AccountConnectorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function connect(UserInterface $user, UserResponseInterface $response): void
    {
        if (!($user instanceof User)) {
            throw new \InvalidArgumentException(sprintf('User must be an instance of %s', User::class));
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}
