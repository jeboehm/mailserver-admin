<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Subscriber;

use App\Entity\Domain;
use App\Service\DKIM\Config\Manager;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;

class DKIMDomainSubscriber implements EventSubscriber
{
    private Manager $manager;

    public function __construct(Manager $manager)
    {
        $this->manager = $manager;
    }

    public function getSubscribedEvents(): array
    {
        return [
            Events::postUpdate,
            Events::postPersist,
            Events::postRemove,
        ];
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->doUpdate($args);
    }

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->doUpdate($args);
    }

    public function postRemove(LifecycleEventArgs $args): void
    {
        $this->doUpdate($args);
    }

    private function doUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Domain) {
            $this->manager->refresh();
        }
    }
}
