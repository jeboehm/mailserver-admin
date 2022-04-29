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
use App\Service\DKIM\DKIMStatusService;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

class DomainInfoSubscriber implements EventSubscriber
{
    public function __construct(private DKIMStatusService $dkimStatusService)
    {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::postLoad];
    }

    public function postLoad(LifecycleEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!($entity instanceof Domain)) {
            return;
        }

        $status = $this->dkimStatusService->getStatus($entity);
        $entity->setDkimStatus($status);
    }
}
