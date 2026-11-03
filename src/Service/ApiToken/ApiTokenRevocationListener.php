<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\ApiToken;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Revokes a user's tokens when they are disabled or their password changes.
 *
 * This runs in the same flush as the change, so both are saved together or not at all. Revocation
 * is permanent: an OIDC login re-enabling the user does not bring the tokens back.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class ApiTokenRevocationListener
{
    public function onFlush(OnFlushEventArgs $event): void
    {
        $entityManager = $event->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $metadata = $entityManager->getClassMetadata(ApiToken::class);

        foreach ($unitOfWork->getScheduledEntityUpdates() as $user) {
            if (!$user instanceof User || !$this->invalidatesTokens($unitOfWork->getEntityChangeSet($user), $user)) {
                continue;
            }

            foreach ($entityManager->getRepository(ApiToken::class)->findBy(['owner' => $user, 'revokedAt' => null]) as $token) {
                $token->revoke();
                $unitOfWork->recomputeSingleEntityChangeSet($metadata, $token);
            }
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function invalidatesTokens(array $changes, User $user): bool
    {
        return isset($changes['password']) || (isset($changes['enabled']) && !$user->getEnabled());
    }
}
