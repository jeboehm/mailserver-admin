<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\User;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Roles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class CurrentActorTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>, ActorRole}>
     */
    public static function grantedRoles(): iterable
    {
        yield 'admin, including inherited roles' => [[Roles::ROLE_ADMIN, Roles::ROLE_DOMAIN_ADMIN, Roles::ROLE_USER], ActorRole::Admin];
        yield 'domain admin' => [[Roles::ROLE_DOMAIN_ADMIN, Roles::ROLE_USER], ActorRole::DomainAdmin];
        yield 'regular user' => [[Roles::ROLE_USER], ActorRole::User];
    }

    /**
     * @param list<string> $granted
     */
    #[DataProvider('grantedRoles')]
    public function testRoleComesFromTheSecurityTokenNotTheEntity(array $granted, ActorRole $expected): void
    {
        $user = new User();
        // The entity disagrees with the token, as it does after a form changed it.
        $user->setAdmin(ActorRole::Admin !== $expected);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => \in_array($role, $granted, true));

        $actor = new CurrentActor($security)->get();

        self::assertSame($expected, $actor->role);
        self::assertSame($user, $actor->user);
    }

    public function testRefusesAnyOtherKindOfUser(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createStub(UserInterface::class));

        $this->expectException(\LogicException::class);

        new CurrentActor($security)->get();
    }

    public function testRefusesAnAnonymousRequest(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $this->expectException(\LogicException::class);

        new CurrentActor($security)->get();
    }
}
