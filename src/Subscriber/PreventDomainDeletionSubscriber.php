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
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\RouterInterface;

readonly class PreventDomainDeletionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [BeforeCrudActionEvent::class => 'onBeforeCrudAction'];
    }

    public function onBeforeCrudAction(BeforeCrudActionEvent $event): void
    {
        $entityDto = $event->getAdminContext()?->getEntity();
        $action = $event->getAdminContext()?->getCrud()?->getCurrentAction();

        if (Action::DELETE !== $action || Domain::class !== $entityDto?->getFqcn()) {
            return;
        }

        $domain = $entityDto->getInstance();
        assert($domain instanceof Domain);

        if (0 === count($domain->getAliases()) && 0 === count($domain->getUsers())) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('admin_domain_index')));

        $session = $event->getAdminContext()?->getRequest()->getSession();

        if (!($session instanceof FlashBagAwareSessionInterface)) {
            return;
        }

        $message = 'Error: This domain currently holds users and/or aliases. Please delete them first.';
        $session->getFlashBag()->add('error', $message);
    }
}
