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
use App\Service\DKIM\KeyGenerationService;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class CreatePrivateKeyOnActivationSubscriber implements EventSubscriber
{
    private KeyGenerationService $keyGenerationService;

    public function __construct(KeyGenerationService $keyGenerationService)
    {
        $this->keyGenerationService = $keyGenerationService;
    }

    public function getSubscribedEvents(): array
    {
        return [Events::preUpdate, Events::prePersist];
    }

    public function preUpdate(LifecycleEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!($entity instanceof Domain)) {
            return;
        }

        if ('' === $entity->getDkimPrivateKey()) {
            $entity->setDkimPrivateKey($this->keyGenerationService->createKeyPair()->getPrivate());
        }

        if ('' === $entity->getDkimSelector()) {
            $entity->setDkimSelector(date('Y'));
        }
    }

    public function prePersist(LifecycleEventArgs $event): void
    {
        $this->preUpdate($event);
    }
}
