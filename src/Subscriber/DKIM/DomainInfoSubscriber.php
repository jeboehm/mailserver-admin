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
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use Pagerfanta\Pagerfanta;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;

class DomainInfoSubscriber implements EventSubscriberInterface
{
    private DKIMStatusService $dkimStatusService;
    private FormatterService $formatterService;
    private KeyGenerationService $keyGenerationService;

    public function __construct(DKIMStatusService $dkimStatusService, FormatterService $formatterService, KeyGenerationService $keyGenerationService)
    {
        $this->dkimStatusService = $dkimStatusService;
        $this->formatterService = $formatterService;
        $this->keyGenerationService = $keyGenerationService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EasyAdminEvents::POST_LIST => 'onPostList',
            EasyAdminEvents::POST_EDIT => 'onPostEdit',
        ];
    }

    public function onPostList(GenericEvent $event): void
    {
        if ('DKIM' !== $event->getArgument('entity')['name']) {
            return;
        }

        /** @var Pagerfanta $paginator */
        $paginator = $event->getArgument('paginator');
        $entities = $paginator->getIterator();

        foreach ($entities as $entity) {
            if (!($entity instanceof Domain)) {
                throw new \DomainException('Wrong entity type');
            }

            $status = $this->dkimStatusService->getStatus($entity);
            $entity->setDkimStatus($status);
        }
    }

    public function onPostEdit(GenericEvent $event): void
    {
        if ('DKIM' !== $event->getArgument('entity')['name']) {
            return;
        }

        /** @var Request $request */
        $request = $event->getArgument('request');
        $item = $request->attributes->get('easyadmin')['item'];

        if (!($item instanceof Domain)) {
            return;
        }

        $status = $this->dkimStatusService->getStatus($item);
        $item->setDkimStatus($status);

        if ('' !== $item->getDkimPrivateKey()) {
            $expectedRecord = $this->formatterService->getTXTRecord(
                $this->keyGenerationService->extractPublicKey($item->getDkimPrivateKey()),
                KeyGenerationService::DIGEST_ALGORITHM
            );
            $item->setExpectedDnsRecord(wordwrap($expectedRecord, 40, "\n", true));
            $item->setCurrentDnsRecord(wordwrap($status->getCurrentRecord(), 40, "\n", true));
        }
    }
}
