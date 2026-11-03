<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use App\Entity\Domain;
use App\Repository\DomainRepository;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\DomainScopePolicy;

/**
 * Turns a domain name passed to a tool into the domain, if the actor may use it.
 */
final readonly class ToolDomainResolver
{
    public function __construct(
        private DomainRepository $domains,
        private DomainScopePolicy $domainScopePolicy,
    ) {
    }

    /**
     * Domains are stored in lowercase and without the root dot a caller may well write.
     */
    public static function normalise(string $name): string
    {
        return rtrim(mb_strtolower($name), '.');
    }

    /**
     * Forms do not offer the field to domain admins. A tool gets the argument anyway, and naming their
     * own domain is harmless.
     *
     * @throws ToolDeniedException for a domain that is missing or out of reach alike
     */
    public function chosenBy(Actor $actor, string $name): Domain
    {
        $domain = $this->domains->findOneBy(['name' => self::normalise($name)]);

        if (null === $domain || !($this->domainScopePolicy->canChooseDomain($actor) || $actor->managesDomain($domain))) {
            throw new ToolDeniedException();
        }

        return $domain;
    }
}
