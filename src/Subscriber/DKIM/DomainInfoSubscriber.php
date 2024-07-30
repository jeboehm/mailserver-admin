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
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postLoad)]
readonly class DomainInfoSubscriber
{
    public function __construct(private DKIMStatusService $dkimStatusService)
    {
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!($entity instanceof Domain)) {
            return;
        }

        $status = $this->dkimStatusService->getStatus($entity);
        $entity->setDkimStatus($status);
    }
}
