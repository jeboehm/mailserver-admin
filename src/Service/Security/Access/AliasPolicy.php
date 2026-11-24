<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AliasPolicy
{
    /**
     * @param string $recipientDelimiter The characters Postfix treats as the start of an address
     *                                   extension (RECIPIENT_DELIMITER). With one, user-tag@domain is delivered to user@domain
     */
    public function __construct(
        private UserRepository $users,
        #[Autowire(env: 'RECIPIENT_DELIMITER')]
        private string $recipientDelimiter = '-',
    ) {
    }

    /**
     * A domain admin may not use an admin's address as an alias source, as that diverts the admin's mail.
     * This reads the values an alias has now, so call it before new values are applied to a stored alias.
     */
    public function canUseAddress(Actor $actor, ?Domain $domain, string $name): bool
    {
        return $actor->managesDomain($domain)
            && ($actor->isAdmin() || !$this->users->isAdminAddress($name, $domain));
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanUseAddress(Actor $actor, ?Domain $domain, string $name): void
    {
        if (!$this->canUseAddress($actor, $domain, $name)) {
            throw new AccessDeniedException('This alias address cannot be used.');
        }
    }

    /**
     * A domain admin may not forward mail to an admin's address, with or without an address extension.
     * Aliases chained through other aliases are not followed.
     */
    public function canUseDestination(Actor $actor, string $destination): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        foreach ($this->deliveryAddresses($destination) as $address) {
            if ($this->users->isAdminEmailAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanUseDestination(Actor $actor, string $destination): void
    {
        if (!$this->canUseDestination($actor, $destination)) {
            throw new AccessDeniedException('This alias destination cannot be used.');
        }
    }

    public function canModify(Actor $actor, Alias $alias): bool
    {
        return $this->canUseAddress($actor, $alias->getDomain(), $alias->getName())
            && $this->canUseDestination($actor, $alias->getDestination());
    }

    public function canDelete(Actor $actor, Alias $alias): bool
    {
        return $this->canModify($actor, $alias);
    }

    /**
     * @throws AccessDeniedException
     */
    public function denyUnlessCanDelete(Actor $actor, Alias $alias): void
    {
        if (!$this->canDelete($actor, $alias)) {
            throw new AccessDeniedException('This alias cannot be deleted.');
        }
    }

    /**
     * The address itself, and the one Postfix falls back to after cutting off the address extension.
     *
     * @return list<string>
     */
    private function deliveryAddresses(string $destination): array
    {
        $destination = mb_strtolower(trim($destination));
        $at = strrpos($destination, '@');

        if (false === $at) {
            return [$destination];
        }

        $localPart = substr($destination, 0, $at);
        $addresses = [$destination];

        if ('' !== $this->recipientDelimiter) {
            $cut = strcspn($localPart, $this->recipientDelimiter);

            if ($cut > 0 && $cut < \strlen($localPart)) {
                $addresses[] = substr($localPart, 0, $cut) . substr($destination, $at);
            }
        }

        return $addresses;
    }
}
