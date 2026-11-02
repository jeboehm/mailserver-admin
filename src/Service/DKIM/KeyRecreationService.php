<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

use App\Entity\Domain;
use App\Service\Security\Access\Actor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class KeyRecreationService
{
    public function __construct(
        private KeyGenerationService $keyGenerationService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Replaces the private key, after which the published DNS record no longer matches.
     *
     * @throws AccessDeniedException
     */
    public function recreate(Actor $actor, Domain $domain): void
    {
        if (!$actor->isAdmin()) {
            throw new AccessDeniedException('Only admins may recreate a DKIM key.');
        }

        $domain->setDkimPrivateKey($this->keyGenerationService->createKeyPair()->getPrivate());

        $this->entityManager->flush();
    }
}
