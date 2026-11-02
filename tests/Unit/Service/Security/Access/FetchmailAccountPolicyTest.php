<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\FetchmailAccountPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class FetchmailAccountPolicyTest extends TestCase
{
    use ActorFixtures;

    private FetchmailAccountPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new FetchmailAccountPolicy();
    }

    public function testAdminSeesEveryAccount(): void
    {
        $qb = $this->queryBuilder(FetchmailAccount::class);

        $this->policy->restrictToVisibleAccounts($qb, $this->actor(ActorRole::Admin, $this->domain()));

        self::assertNull($qb->getDQLPart('where'));
    }

    public function testDomainAdminSeesAccountsOfUsersInTheirDomain(): void
    {
        $domain = $this->domain();
        $qb = $this->queryBuilder(FetchmailAccount::class);

        $this->policy->restrictToVisibleAccounts($qb, $this->actor(ActorRole::DomainAdmin, $domain));

        self::assertSame('user.domain = :domain', (string) $qb->getDQLPart('where'));
        self::assertStringContainsString('INNER JOIN entity.user user', $qb->getDQL());
        self::assertSame($domain, $qb->getParameter('domain')?->getValue());
    }

    public function testRegularUserSeesOnlyTheirOwnAccounts(): void
    {
        $actor = $this->actor(ActorRole::User, $this->domain());
        $qb = $this->queryBuilder(FetchmailAccount::class);

        $this->policy->restrictToVisibleAccounts($qb, $actor);

        self::assertSame('entity.user = :user', (string) $qb->getDQLPart('where'));
        self::assertSame($actor->user, $qb->getParameter('user')?->getValue());
    }

    public function testDomainAdminWithoutDomainHasNoVisibleAccounts(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->policy->restrictToVisibleAccounts(
            $this->queryBuilder(FetchmailAccount::class),
            new Actor(new User(), ActorRole::DomainAdmin)
        );
    }

    public function testAdminMayAssignAccountsToAnyUser(): void
    {
        $qb = $this->queryBuilder(User::class);

        $this->policy->restrictToAssignableOwners($qb, $this->actor(ActorRole::Admin, $this->domain()));

        self::assertNull($qb->getDQLPart('where'));
    }

    public function testDomainAdminMayAssignAccountsToUsersInTheirDomain(): void
    {
        $domain = $this->domain();
        $qb = $this->queryBuilder(User::class);

        $this->policy->restrictToAssignableOwners($qb, $this->actor(ActorRole::DomainAdmin, $domain));

        self::assertSame('entity.domain = :domain', (string) $qb->getDQLPart('where'));
        self::assertSame($domain, $qb->getParameter('domain')?->getValue());
    }

    public function testRegularUserMayAssignAccountsOnlyToThemselves(): void
    {
        $actor = $this->actor(ActorRole::User, $this->domain());
        $qb = $this->queryBuilder(User::class);

        $this->policy->restrictToAssignableOwners($qb, $actor);

        self::assertSame('entity = :owner', (string) $qb->getDQLPart('where'));
        self::assertSame($actor->user, $qb->getParameter('owner')?->getValue());
    }

    public function testDomainAdminWithoutDomainHasNoAssignableOwners(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->policy->restrictToAssignableOwners(
            $this->queryBuilder(User::class),
            new Actor(new User(), ActorRole::DomainAdmin)
        );
    }

    /**
     * @return iterable<string, array{ActorRole, bool, bool, bool}>
     */
    public static function assignableOwners(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true, true, true];
        yield 'domain admin' => [ActorRole::DomainAdmin, true, true, false];
        yield 'regular user' => [ActorRole::User, true, false, false];
    }

    #[DataProvider('assignableOwners')]
    public function testCanAssignTo(ActorRole $role, bool $self, bool $sameDomain, bool $foreignDomain): void
    {
        $domain = $this->domain();
        $actor = $this->actor($role, $domain);

        self::assertSame($self, $this->policy->canAssignTo($actor, $actor->user));
        self::assertSame($sameDomain, $this->policy->canAssignTo($actor, $this->user($domain)));
        self::assertSame($foreignDomain, $this->policy->canAssignTo($actor, $this->user($this->domain('foreign.example'))));
    }

    /**
     * @return iterable<string, array{ActorRole, bool, bool, bool}>
     */
    public static function accountDeleters(): iterable
    {
        yield 'admin deletes all' => [ActorRole::Admin, true, true, true];
        yield 'domain admin deletes within their domain' => [ActorRole::DomainAdmin, true, true, false];
        yield 'regular user deletes their own' => [ActorRole::User, true, false, false];
    }

    #[DataProvider('accountDeleters')]
    public function testCanDelete(ActorRole $role, bool $own, bool $sameDomain, bool $foreignDomain): void
    {
        $domain = $this->domain();
        $actor = $this->actor($role, $domain);

        self::assertSame($own, $this->policy->canDelete($actor, $this->account($actor->user)));
        self::assertSame($sameDomain, $this->policy->canDelete($actor, $this->account($this->user($domain))));
        self::assertSame($foreignDomain, $this->policy->canDelete($actor, $this->account($this->user($this->domain('foreign.example')))));
    }

    /**
     * @return iterable<string, array{ActorRole, bool}>
     */
    public static function ownerlessAccountDeleters(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true];
        yield 'domain admin' => [ActorRole::DomainAdmin, false];
        yield 'regular user' => [ActorRole::User, false];
    }

    #[DataProvider('ownerlessAccountDeleters')]
    public function testOnlyAdminsMayDeleteAnAccountWithoutOwner(ActorRole $role, bool $expected): void
    {
        self::assertSame($expected, $this->policy->canDelete($this->actor($role, $this->domain()), new FetchmailAccount()));
    }

    public function testDeletingAForeignAccountIsRejected(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->policy->denyUnlessCanDelete(
            $this->actor(ActorRole::User, $this->domain()),
            $this->account($this->user($this->domain('foreign.example')))
        );
    }

    #[DoesNotPerformAssertions]
    public function testDeletingAnOwnAccountIsAllowed(): void
    {
        $actor = $this->actor(ActorRole::User, $this->domain());

        $this->policy->denyUnlessCanDelete($actor, $this->account($actor->user));
    }

    /**
     * @return iterable<string, array{ActorRole, bool}>
     */
    public static function ownerChoosers(): iterable
    {
        yield 'admin' => [ActorRole::Admin, true];
        yield 'domain admin' => [ActorRole::DomainAdmin, true];
        yield 'regular user' => [ActorRole::User, false];
    }

    #[DataProvider('ownerChoosers')]
    public function testRegularUsersDoNotChooseAnOwner(ActorRole $role, bool $expected): void
    {
        self::assertSame($expected, $this->policy->canChooseOwner($this->actor($role, $this->domain())));
    }

    #[DataProvider('allRoles')]
    public function testNewAccountsDefaultToTheActor(ActorRole $role): void
    {
        $actor = $this->actor($role, $this->domain());

        self::assertSame($actor->user, $this->policy->defaultOwner($actor));
    }

    private function account(User $owner): FetchmailAccount
    {
        $account = new FetchmailAccount();
        $account->setUser($owner);

        return $account;
    }

    /**
     * @param class-string $entity
     */
    private function queryBuilder(string $entity): QueryBuilder
    {
        return new QueryBuilder($this->createStub(EntityManagerInterface::class))
            ->select('entity')
            ->from($entity, 'entity');
    }
}
