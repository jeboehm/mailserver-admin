<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\DkimPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DkimPolicyTest extends TestCase
{
    use ActorFixturesTrait;

    /**
     * @return iterable<string, array{ActorRole, bool}>
     */
    public static function keyRecreators(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true];
        yield 'domain admin' => [ActorRole::DomainAdmin, false];
        yield 'regular user' => [ActorRole::User, false];
    }

    #[DataProvider('keyRecreators')]
    public function testOnlyAdminsMayRecreateAKey(ActorRole $role, bool $expected): void
    {
        self::assertSame($expected, new DkimPolicy()->canRecreateKey($this->actor($role, $this->domain())));
    }
}
