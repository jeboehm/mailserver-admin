<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\Access;

/**
 * Whose fetchmail accounts an actor may manage.
 */
enum OwnerScope
{
    case Anyone;
    case OwnDomain;
    case OnlyThemselves;

    public static function of(Actor $actor): self
    {
        return match ($actor->role) {
            ActorRole::Admin => self::Anyone,
            ActorRole::DomainAdmin => self::OwnDomain,
            ActorRole::User => self::OnlyThemselves,
        };
    }
}
