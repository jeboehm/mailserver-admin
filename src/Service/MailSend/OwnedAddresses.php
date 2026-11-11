<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use App\Entity\Alias;
use App\Entity\User;
use App\Repository\AliasRepository;

/**
 * The addresses a user may send as: their primary address, plus every alias that has the primary
 * address as one of its destinations. This follows the email-submission query behind Postfix's
 * smtpd_sender_login_maps, narrowed on purpose:
 *
 * - Postfix lower-cases the sender but not the stored data, so a stored address or destination
 *   with an upper-case letter can never be relied on and does not count.
 * - A catch-all, an address extension, an alias chain and an external destination do not count.
 * - Every role owns the same: its own addresses, never those of other users.
 */
final readonly class OwnedAddresses
{
    public function __construct(private AliasRepository $aliasRepository)
    {
    }

    /**
     * @return list<string> the primary address first, then the aliases in alphabetical order
     */
    public function of(User $user): array
    {
        $primary = (string) $user;
        $aliases = array_map(
            static fn (Alias $alias): string => \sprintf('%s@%s', $alias->getName(), $alias->getDomain()?->getName()),
            array_filter(
                $this->aliasRepository->findByDestination($primary),
                static fn (Alias $alias): bool => '' !== $alias->getName() && null !== $alias->getDomain(),
            ),
        );
        sort($aliases);

        return array_values(array_filter(
            array_unique([$primary, ...$aliases]),
            static fn (string $address): bool => $address === mb_strtolower($address),
        ));
    }

    public function owns(User $user, string $address): bool
    {
        return \in_array(mb_strtolower($address), $this->of($user), true);
    }
}
