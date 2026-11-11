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
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

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
        return match ($token->getSenderMode()) {
            SenderMode::Primary => $this->primary($user, $fromAddress),
            SenderMode::Fixed => $this->fixed($token, $user, $fromAddress),
            SenderMode::Any => $this->chosen($user, $fromAddress),
        };
    }

    private function primary(User $user, ?string $fromAddress): string
    {
        $primary = (string) $user;

        if (null !== $fromAddress) {
            throw new SenderNotAllowedException(\sprintf('This token always uses your primary address %s as the sender. Leave out "from_address".', $primary));
        }

        return $primary;
    }

    private function fixed(ApiToken $token, User $user, ?string $fromAddress): string
    {
        $bound = (string) $token->getSenderAddress();

        if (null !== $fromAddress) {
            throw new SenderNotAllowedException(\sprintf('This token always uses %s as the sender. Leave out "from_address".', $bound));
        }

        if (!$this->ownedAddresses->owns($user, $bound)) {
            throw new SenderNotAllowedException(\sprintf('This token is bound to %s, which is no longer one of your addresses, so nothing was sent or saved. Ask the owner to restore the alias or to create a token with another sender.', $bound));
        }

        return $bound;
    }

    private function chosen(User $user, ?string $fromAddress): string
    {
        if (null === $fromAddress) {
            return (string) $user;
        }

        try {
            $address = new Address($fromAddress);
        } catch (InvalidArgumentException|RfcComplianceException) {
            throw new SenderNotAllowedException('"from_address" must be a plain address such as name@example.com.');
        }

        // Address validates without a display name, so this only fails on what it trims or rewrites.
        if ($address->getAddress() !== $fromAddress) {
            throw new SenderNotAllowedException('"from_address" must be a plain address such as name@example.com.');
        }

        if (!$this->ownedAddresses->owns($user, $fromAddress)) {
            throw new SenderNotAllowedException(\sprintf('%s is not one of your addresses, so nothing was sent or saved. Use one of the addresses "send_as" in whoami lists, or leave out "from_address" to use your primary address.', $fromAddress));
        }

        return mb_strtolower($fromAddress);
    }
}
