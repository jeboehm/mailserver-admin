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
        $primary = (string) $user;

        if (null !== $fromAddress) {
            throw new SenderNotAllowedException(match ($token->getSenderMode()) {
                SenderMode::Fixed => \sprintf('This token always uses %s as the sender. Leave out "from_address".', $token->getSenderAddress()), default => \sprintf('This token always uses your primary address %s as the sender. Leave out "from_address".', $primary),
            });
        }

        $bound = $token->getSenderAddress();

        if (SenderMode::Fixed !== $token->getSenderMode() || null === $bound) {
            return $primary;
        }

        if (!$this->ownedAddresses->owns($user, $bound)) {
            throw new SenderNotAllowedException(\sprintf('This token is bound to %s, which is no longer one of your addresses, so nothing was sent or saved. Ask the owner to restore the alias or to create a token with another sender.', $bound));
        }

        return $bound;
    }
}
