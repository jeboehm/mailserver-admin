<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsEntityListener(event: Events::prePersist, method: 'processUserPassword', entity: User::class)]
#[AsEntityListener(event: Events::preUpdate, method: 'processUserPassword', entity: User::class)]
readonly class PasswordService
{
    public function __construct(private PasswordHasherFactoryInterface $passwordHasherFactory)
    {
    }

    public function processUserPassword(User $user): void
    {
        if (null !== $user->getPlainPassword()) {
            $passwordHasher = $this->passwordHasherFactory->getPasswordHasher($user);
            $user->setPassword($passwordHasher->hash($user->getPlainPassword()));
        }
    }
}
