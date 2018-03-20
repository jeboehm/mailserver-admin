<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Subscriber;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;

class ChangePasswordSubscriber implements EventSubscriberInterface
{
    const SALT_LENGTH = 16;

    private $encoderFactory;

    public function __construct(EncoderFactoryInterface $encoderFactory)
    {
        $this->encoderFactory = $encoderFactory;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EasyAdminEvents::PRE_UPDATE => 'onPreUpdatePersist',
            EasyAdminEvents::PRE_PERSIST => 'onPreUpdatePersist',
        ];
    }

    public function onPreUpdatePersist(GenericEvent $event): void
    {
        $user = $event->getSubject();

        if (!($user instanceof User)) {
            return;
        }

        if (null !== $user->getPlainPassword()) {
            $encoder = $this->encoderFactory->getEncoder($user);
            $user->setPassword(
                $encoder->encodePassword(
                    $user->getPlainPassword(),
                    substr(sha1(random_bytes(50)), 0, self::SALT_LENGTH)
                )
            );
        }
    }
}
