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
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class ChangePasswordSubscriber implements EventSubscriber
{
    public function __construct(private PasswordService $passwordService)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::preUpdate, Events::prePersist];
    }

    public function preUpdate(LifecycleEventArgs $event): void
    {
        $user = $event->getObject();

        if (!($user instanceof User)) {
            return;
        }

        $this->passwordService->processUserPassword($user);
    }

    public function prePersist(LifecycleEventArgs $event): void
    {
        $this->preUpdate($event);
    }
}
