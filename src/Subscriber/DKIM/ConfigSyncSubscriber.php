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
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postPersist)]
readonly class ConfigSyncSubscriber
{
    public function __construct(private Manager $manager)
    {
    }

    public function postUpdate(PostUpdateEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!($entity instanceof Domain)) {
            return;
        }

        $this->manager->refresh();
    }

    public function postPersist(PostPersistEventArgs $event): void
    {
        $entity = $event->getObject();

        if (!($entity instanceof Domain)) {
            return;
        }

        $this->manager->refresh();
    }
}
