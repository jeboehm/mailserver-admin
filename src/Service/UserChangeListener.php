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
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: User::class)]
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: User::class)]
readonly class UserChangeListener
{
    public function __construct(private PasswordHasherFactoryInterface $passwordHasherFactory)
    {
    }

    final public function preUpdate(User $user, PreUpdateEventArgs $args): void
    {
        $this->processUserPassword($user);
    }

    final public function prePersist(User $user, PrePersistEventArgs $args): void
    {
        $this->processUserPassword($user);
    }

    public function processUserPassword(User $user): void
    {
        if (!empty($user->getPlainPassword())) {
            $passwordHasher = $this->passwordHasherFactory->getPasswordHasher($user);
            $user->setPassword($passwordHasher->hash($user->getPlainPassword()));
        }
    }
}
