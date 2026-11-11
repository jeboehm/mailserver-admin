<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\ApiToken;

/**
 * The sender a token sends and drafts as: its mode, and the address for a fixed sender. A fixed
 * sender always has an address, in lower case, and the other modes never have one.
 */
final readonly class SenderBinding
{
    public ?string $address;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(public SenderMode $mode, ?string $address = null)
    {
        if ((SenderMode::Fixed === $mode) !== (null !== $address && '' !== $address)) {
            throw new \InvalidArgumentException('A fixed sender needs an address, and only a fixed sender has one.');
        }

        $this->address = null === $address ? null : mb_strtolower($address);
    }

    public static function primary(): self
    {
        return new self(SenderMode::Primary);
    }

    /**
     * What a form chose: the address it submits along counts for a fixed sender only.
     *
     * @throws \InvalidArgumentException for a fixed sender without an address
     */
    public static function chosen(SenderMode $mode, ?string $address): self
    {
        return new self($mode, SenderMode::Fixed === $mode ? $address : null);
    }

    public function isFixed(): bool
    {
        return SenderMode::Fixed === $this->mode;
    }
}
