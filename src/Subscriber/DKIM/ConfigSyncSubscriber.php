<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Subscriber\DKIM;

use App\Entity\Domain;
use App\Service\DKIM\Config\Manager;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class ConfigSyncSubscriber implements EventSubscriberInterface
{
    private Manager $manager;

    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EasyAdminEvents::POST_UPDATE => 'onPostUpdate',
        ];
    }

    public function onPostUpdate(GenericEvent $event): void
    {
        $entity = $event->getArgument('entity');

        if (!($entity instanceof Domain)) {
            return;
        }

        $this->manager->refresh();
    }
}
