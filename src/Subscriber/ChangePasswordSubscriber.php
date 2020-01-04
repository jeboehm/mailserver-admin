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
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class ChangePasswordSubscriber implements EventSubscriberInterface
{
    private PasswordService $passwordService;

    public function __construct(PasswordService $passwordService)
    {
        $this->passwordService = $passwordService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EasyAdminEvents::PRE_UPDATE => 'onPreUpdatePersist',
            EasyAdminEvents::PRE_PERSIST => 'onPreUpdatePersist',
        ];
    }

    public function onPreUpdatePersist(GenericEvent $event): void
    {
        $user = $event->getSubject();

        if (!($user instanceof User)) {
            return;
        }

        $this->passwordService->processUserPassword($user);
    }
}
