<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

enum ActorRole
{
    case Admin;
    case DomainAdmin;
    case User;

    public function isAtLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Admin => 2,
            self::DomainAdmin => 1,
            self::User => 0,
        };
    }
}
