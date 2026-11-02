<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class DomainPolicy
{
    public function __construct(private DomainRepository $domainRepository)
    {
    }

    public function canDelete(Actor $actor, Domain $domain): bool
    {
        return $actor->isAdmin()
            && 0 === $domain->getUsers()->count()
            && 0 === $domain->getAliases()->count();
    }

    /**
     * Deleting a domain cascades to its users and aliases, so this guards against data loss.
     *
     * @throws AccessDeniedException
     */
    public function denyUnlessCanDelete(Actor $actor, Domain $domain): void
    {
        if (!$this->canDelete($actor, $domain)) {
            throw new AccessDeniedException('This domain cannot be deleted.');
        }
    }

    /**
     * @return list<Domain>
     */
    public function domainsForDnsValidation(Actor $actor): array
    {
        if ($actor->isAdmin()) {
            return $this->domainRepository->findBy([], ['name' => 'ASC']);
        }

        if (!$actor->isDomainAdmin()) {
            return [];
        }

        $domain = $actor->user->getDomain();

        if (null === $domain) {
            throw new \RuntimeException('Domain admin user has no domain');
        }

        return [$domain];
    }
}
