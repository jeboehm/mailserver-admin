<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security;

use Symfony\Component\Security\Core\User\UserInterface;

readonly class OAuthStaticUser implements UserInterface
{
    public function __construct(
        private string $identifier,
        private bool $admin
    ) {
    }

    public function getRoles(): array
    {
        return $this->admin ? ['ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
