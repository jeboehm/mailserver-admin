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
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\DomainScopePolicy;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainScopePolicyTest extends TestCase
{
    use ActorFixtures;

    private DomainScopePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DomainScopePolicy();
    }

    public function testAdminSeesEveryDomain(): void
    {
        $qb = $this->queryBuilder();

        $this->policy->restrictToVisibleDomains($qb, $this->actor(ActorRole::Admin, $this->domain()));

        self::assertNull($qb->getDQLPart('where'));
    }

    public function testDomainAdminSeesOnlyTheirOwnDomain(): void
    {
        $domain = $this->domain();
        $qb = $this->queryBuilder();

        $this->policy->restrictToVisibleDomains($qb, $this->actor(ActorRole::DomainAdmin, $domain));

        self::assertSame('entity.domain = :domain', (string) $qb->getDQLPart('where'));
        self::assertSame($domain, $qb->getParameter('domain')?->getValue());
    }

    public function testRegularUserSeesNothing(): void
    {
        $qb = $this->queryBuilder();

        $this->policy->restrictToVisibleDomains($qb, $this->actor(ActorRole::User, $this->domain()));

        self::assertSame('1 = 0', (string) $qb->getDQLPart('where'));
    }

    public function testDomainAdminWithoutDomainIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->policy->restrictToVisibleDomains($this->queryBuilder(), new Actor(new User(), ActorRole::DomainAdmin));
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
    public function testOnlyAdminsMayChooseADomain(ActorRole $role, bool $expected): void
    {
        self::assertSame($expected, $this->policy->canChooseDomain($this->actor($role, $this->domain())));
    }

    #[DataProvider('allRoles')]
    public function testNewEntitiesDefaultToTheActorsDomain(ActorRole $role): void
    {
        $domain = $this->domain();

        self::assertSame($domain, $this->policy->defaultDomain($this->actor($role, $domain)));
    }

    public function testActorWithoutDomainHasNoDefault(): void
    {
        self::assertNull($this->policy->defaultDomain(new Actor(new User(), ActorRole::Admin)));
    }

    private function queryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->createStub(EntityManagerInterface::class))
            ->select('entity')
            ->from(User::class, 'entity');
    }
}
