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
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::prePersist)]
readonly class CreatePrivateKeyOnActivationSubscriber
{
    public function __construct(private KeyGenerationService $keyGenerationService)
    {
    }

    public function preUpdate(PreUpdateEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!($entity instanceof Domain)) {
            return;
        }

        $this->setPrivateKey($entity);
    }

    public function prePersist(PrePersistEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!($entity instanceof Domain)) {
            return;
        }

        $this->setPrivateKey($entity);
    }

    private function setPrivateKey(Domain $entity): void
    {
        if ('' === $entity->getDkimPrivateKey()) {
            $entity->setDkimPrivateKey($this->keyGenerationService->createKeyPair()->getPrivate());
        }

        if ('' === $entity->getDkimSelector()) {
            $entity->setDkimSelector(date('Y'));
        }
    }
}
