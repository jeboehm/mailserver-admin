<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Subscriber;

use App\Entity\User;
use App\Service\PasswordService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::prePersist)]
readonly class ChangePasswordSubscriber
{
    public function __construct(private PasswordService $passwordService)
    {
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $user = $event->getObject();

        if (!($user instanceof User)) {
            return;
        }

        $this->passwordService->processUserPassword($user);
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $user = $event->getObject();

        if (!($user instanceof User)) {
            return;
        }

        $this->passwordService->processUserPassword($user);
    }
}
