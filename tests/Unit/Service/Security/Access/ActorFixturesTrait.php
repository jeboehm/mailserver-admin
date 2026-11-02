<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\Domain;
use App\Entity\User;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;

trait ActorFixturesTrait
{
    /**
     * @return iterable<string, array{ActorRole}>
     */
    public static function allRoles(): iterable
    {
        yield 'admin' => [ActorRole::Admin];
        yield 'domain admin' => [ActorRole::DomainAdmin];
        yield 'regular user' => [ActorRole::User];
    }

    private function domain(string $name = 'example.com'): Domain
    {
        $domain = new Domain();
        $domain->setName($name);

        return $domain;
    }

    private function user(Domain $domain, string $name = 'someone'): User
    {
        $user = new User();
        $user->setDomain($domain);
        $user->setName($name);

        return $user;
    }

    private function actor(ActorRole $role, Domain $domain): Actor
    {
        return new Actor($this->user($domain, 'actor'), $role);
    }
}
