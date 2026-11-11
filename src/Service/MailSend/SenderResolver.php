<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Exception\MailSend\SenderNotAllowedException;
use App\Service\ApiToken\SenderBinding;
use App\Service\ApiToken\SenderMode;

/**
 * Decides the address a call of message_send or message_draft uses, from the sender mode of the
 * token and the optional from_address. It runs before anything is consumed: no rate limit, no
 * mailbox credential.
 */
final readonly class SenderResolver
{
    public function __construct(private OwnedAddresses $ownedAddresses)
    {
    }

    /**
     * @throws SenderNotAllowedException
     */
    public function resolve(ApiToken $token, User $user, ?string $fromAddress): string
    {
        $sender = $token->getSender() ?? SenderBinding::primary();

        return match ($sender->mode) {
            SenderMode::Primary => $this->primary($user, $fromAddress),
            SenderMode::Fixed => $this->fixed($token, (string) $sender->address, $fromAddress),
            SenderMode::Any => $this->chosen($user, $fromAddress),
        };
    }

    /**
     * Whether the address of a fixed sender is still one of the token owner's, checked the way a
     * send checks it. Nothing revokes a token whose address went away, so the token list shows this.
     *
     * @return bool|null null for a token without a fixed sender
     */
    public function isBoundAddressOwned(ApiToken $token): ?bool
    {
        $address = $token->getSender()?->address;

        return null === $address ? null : $this->ownedAddresses->owns($token->getOwner(), $address);
    }

    private function primary(User $user, ?string $fromAddress): string
    {
        $primary = (string) $user;

        if (null !== $fromAddress) {
            throw new SenderNotAllowedException(\sprintf('This token always uses your primary address %s as the sender. Leave out "from_address".', $primary));
        }

        return $primary;
    }

    /**
     * The actor of a tool call is always the owner of its token.
     */
    private function fixed(ApiToken $token, string $bound, ?string $fromAddress): string
    {
        if (null !== $fromAddress) {
            throw new SenderNotAllowedException(\sprintf('This token always uses %s as the sender. Leave out "from_address".', $bound));
        }

        if (true !== $this->isBoundAddressOwned($token)) {
            throw new SenderNotAllowedException(\sprintf('This token is bound to %s, which is no longer one of your addresses, so nothing was sent or saved. Ask the owner to restore the alias or to create a token with another sender.', $bound));
        }

        return $bound;
    }

    private function chosen(User $user, ?string $fromAddress): string
    {
        if (null === $fromAddress) {
            return (string) $user;
        }

        // Address validates without a display name, so the comparison only fails on what it trims.
        if ($fromAddress !== PlainAddress::parse($fromAddress)?->getAddress()) {
            throw new SenderNotAllowedException(\sprintf('"from_address" must be %s.', PlainAddress::DESCRIPTION));
        }

        if (!$this->ownedAddresses->owns($user, $fromAddress)) {
            throw new SenderNotAllowedException(\sprintf('%s is not one of your addresses, so nothing was sent or saved. Use one of the addresses "send_as" in whoami lists, or leave out "from_address" to use your primary address.', $fromAddress));
        }

        return mb_strtolower($fromAddress);
    }
}
