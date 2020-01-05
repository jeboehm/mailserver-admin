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
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class CreatePrivateKeyOnActivationSubscriber implements EventSubscriberInterface
{
    private KeyGenerationService $keyGenerationService;

    public function __construct(KeyGenerationService $keyGenerationService)
    {
        $this->keyGenerationService = $keyGenerationService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EasyAdminEvents::PRE_UPDATE => 'onPreUpdate',
        ];
    }

    public function onPreUpdate(GenericEvent $event): void
    {
        $entity = $event->getArgument('entity');

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
}
