<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\User\OAuth;

use HWI\Bundle\OAuthBundle\Event\GetResponseUserEvent;
use HWI\Bundle\OAuthBundle\HWIOAuthEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

readonly class DenyRegistrationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%env(bool:OAUTH_ENABLED)%')]
        private bool $enabled,
        #[Autowire('%env(bool:OAUTH_CREATE_USER)%')]
        private bool $canCreateUser,
        private Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [HWIOAuthEvents::REGISTRATION_INITIALIZE => 'onRegistrationInitialize'];
    }

    public function onRegistrationInitialize(GetResponseUserEvent $event): void
    {
        if (!$this->enabled || !$this->canCreateUser) {
            $response = new Response(
                $this->twig->render('admin/oauth/error.html.twig'),
                Response::HTTP_FORBIDDEN
            );

            $event->setResponse($response);
        }
    }
}
