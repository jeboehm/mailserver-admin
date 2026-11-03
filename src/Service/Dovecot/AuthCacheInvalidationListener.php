<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Dovecot;

use App\Entity\Domain;
use App\Entity\User;
use App\Exception\Dovecot\DoveadmException;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Keeps Dovecot's auth cache in line with changes to users.
 *
 * Dovecot caches both accepted and refused logins. Until an entry expires, a disabled or deleted
 * user can still log in, an old password keeps working next to the new one, and a re-enabled user
 * stays refused. Flushing the user's entries makes the next login check the database again.
 *
 * The flush runs after the commit, because a login in between would cache the old state again.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuthCacheInvalidationListener
{
    private const array LOGIN_RELEVANT_FIELDS = ['name', 'domain', 'password', 'enabled', 'sendOnly'];

    /** @var list<string> */
    private array $addresses = [];

    public function __construct(
        private readonly DoveadmHttpClient $doveadmHttpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $unitOfWork = $event->getObjectManager()->getUnitOfWork();
        $addresses = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof User) {
                $addresses[] = $this->address($entity->getName(), $entity->getDomain());
            }
        }

        foreach ($unitOfWork->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof User) {
                $addresses[] = $this->address($entity->getName(), $entity->getDomain());
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof User) {
                continue;
            }

            $changes = $unitOfWork->getEntityChangeSet($entity);

            if ([] === array_intersect(self::LOGIN_RELEVANT_FIELDS, array_keys($changes))) {
                continue;
            }

            $addresses[] = $this->address($entity->getName(), $entity->getDomain());
            $addresses[] = $this->address(
                $changes['name'][0] ?? $entity->getName(),
                $changes['domain'][0] ?? $entity->getDomain(),
            );
        }

        $this->addresses = array_values(array_unique($addresses));
    }

    public function postFlush(PostFlushEventArgs $event): void
    {
        $addresses = $this->addresses;
        $this->addresses = [];

        if ([] === $addresses) {
            return;
        }

        try {
            $this->doveadmHttpClient->flushAuthCache($addresses);
        } catch (DoveadmException $e) {
            // The change itself is saved. Dovecot catches up when the cache entries expire.
            $this->logger->error('Could not flush the Dovecot auth cache: {message}', [
                'message' => $e->getMessage(),
                'addresses' => $addresses,
            ]);
        }
    }

    private function address(string $name, ?Domain $domain): string
    {
        return strtolower(\sprintf('%s@%s', $name, $domain?->getName() ?? ''));
    }
}
