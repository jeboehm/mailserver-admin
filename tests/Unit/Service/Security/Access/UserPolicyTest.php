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
use App\Service\Security\Access\UserPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class UserPolicyTest extends TestCase
{
    use ActorFixturesTrait;

    private UserPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new UserPolicy();
    }

    /**
     * @return iterable<string, array{ActorRole, bool}>
     */
    public static function onlyAdmins(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true];
        yield 'domain admin' => [ActorRole::DomainAdmin, false];
        yield 'regular user' => [ActorRole::User, false];
    }

    #[DataProvider('onlyAdmins')]
    public function testOnlyAdminsMaySetTheAdminFlag(ActorRole $role, bool $expected): void
    {
        self::assertSame($expected, $this->policy->canSetAdminFlag($this->actor($role, $this->domain())));
    }

    /**
     * @return iterable<string, array{ActorRole, bool, bool}>
     */
    public static function ownRoles(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true, false];
        yield 'domain admin' => [ActorRole::DomainAdmin, false, true];
        yield 'regular user' => [ActorRole::User, false, false];
    }

    #[DataProvider('ownRoles')]
    public function testSelfUpdateCannotRaiseOwnRole(ActorRole $role, bool $admin, bool $domainAdmin): void
    {
        $actor = $this->actor($role, $this->domain());
        $actor->user->setAdmin(true);
        $actor->user->setDomainAdmin(true);

        $this->policy->keepOwnRoleAndEnabledState($actor, $actor->user);

        self::assertSame($admin, $actor->user->isAdmin());
        self::assertSame($domainAdmin, $actor->user->isDomainAdmin());
    }

    #[DataProvider('ownRoles')]
    public function testSelfUpdateCannotDropOwnRoleOrDisableOneself(ActorRole $role, bool $admin, bool $domainAdmin): void
    {
        $actor = $this->actor($role, $this->domain());
        $actor->user->setAdmin(false);
        $actor->user->setDomainAdmin(false);
        $actor->user->setEnabled(false);

        $this->policy->keepOwnRoleAndEnabledState($actor, $actor->user);

        self::assertSame($admin, $actor->user->isAdmin());
        self::assertSame($domainAdmin, $actor->user->isDomainAdmin());
        self::assertTrue($actor->user->getEnabled());
    }

    #[DataProvider('allRoles')]
    public function testUpdatingSomeoneElseLeavesTheirStateAlone(ActorRole $role): void
    {
        $domain = $this->domain();
        $other = $this->user($domain);
        $other->setDomainAdmin(true);
        $other->setEnabled(false);

        $this->policy->keepOwnRoleAndEnabledState($this->actor($role, $domain), $other);

        self::assertTrue($other->isDomainAdmin());
        self::assertFalse($other->isAdmin());
        self::assertFalse($other->getEnabled());
    }

    /**
     * @return iterable<string, array{ActorRole, bool, bool}>
     */
    public static function deletableUsers(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true, true];
        yield 'domain admin' => [ActorRole::DomainAdmin, true, false];
        yield 'regular user' => [ActorRole::User, false, false];
    }

    #[DataProvider('deletableUsers')]
    public function testWhoMayBeDeleted(ActorRole $role, bool $sameDomain, bool $foreignDomain): void
    {
        $domain = $this->domain();
        $actor = $this->actor($role, $domain);

        self::assertSame($sameDomain, $this->policy->canDelete($actor, $this->user($domain)));
        self::assertSame($foreignDomain, $this->policy->canDelete($actor, $this->user($this->domain('foreign.example'))));
    }

    #[DataProvider('allRoles')]
    public function testNobodyMayDeleteThemselves(ActorRole $role): void
    {
        $actor = $this->actor($role, $this->domain());

        self::assertFalse($this->policy->canDelete($actor, $actor->user));
    }

    #[DataProvider('allRoles')]
    public function testDeletingOneselfIsRejected(ActorRole $role): void
    {
        $actor = $this->actor($role, $this->domain());

        $this->expectException(AccessDeniedException::class);

        $this->policy->denyUnlessCanDelete($actor, $actor->user);
    }

    public function testAllowedDeletionIsNotRejected(): void
    {
        $domain = $this->domain();

        $this->expectNotToPerformAssertions();

        $this->policy->denyUnlessCanDelete($this->actor(ActorRole::DomainAdmin, $domain), $this->user($domain));
    }

    public function testDeletingInAForeignDomainIsRejected(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->policy->denyUnlessCanDelete(
            $this->actor(ActorRole::DomainAdmin, $this->domain()),
            $this->user($this->domain('foreign.example'))
        );
    }
}
