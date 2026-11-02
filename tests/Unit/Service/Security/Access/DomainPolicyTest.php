<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\Access;

use App\Entity\Alias;
use App\Entity\User;
use App\Repository\DomainRepository;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\DomainPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class DomainPolicyTest extends TestCase
{
    use ActorFixtures;

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
    public function testOnlyAdminsMayDeleteAnEmptyDomain(ActorRole $role, bool $expected): void
    {
        $domain = $this->domain();

        self::assertSame($expected, $this->policy()->canDelete($this->actor($role, $domain), $domain));
    }

    #[DataProvider('allRoles')]
    public function testNobodyMayDeleteADomainWithUsers(ActorRole $role): void
    {
        $domain = $this->domain();
        $domain->getUsers()->add(new User());

        self::assertFalse($this->policy()->canDelete($this->actor($role, $domain), $domain));
    }

    #[DataProvider('allRoles')]
    public function testNobodyMayDeleteADomainWithAliases(ActorRole $role): void
    {
        $domain = $this->domain();
        $domain->getAliases()->add(new Alias());

        self::assertFalse($this->policy()->canDelete($this->actor($role, $domain), $domain));
    }

    public function testDeletingANonEmptyDomainIsRejected(): void
    {
        $domain = $this->domain();
        $domain->getUsers()->add(new User());

        $this->expectException(AccessDeniedException::class);

        $this->policy()->denyUnlessCanDelete($this->actor(ActorRole::Admin, $domain), $domain);
    }

    public function testDeletingAsDomainAdminIsRejected(): void
    {
        $domain = $this->domain();

        $this->expectException(AccessDeniedException::class);

        $this->policy()->denyUnlessCanDelete($this->actor(ActorRole::DomainAdmin, $domain), $domain);
    }

    public function testDeletingAnEmptyDomainAsAdminIsNotRejected(): void
    {
        $domain = $this->domain();

        $this->expectNotToPerformAssertions();

        $this->policy()->denyUnlessCanDelete($this->actor(ActorRole::Admin, $domain), $domain);
    }

    public function testAdminValidatesDnsForAllDomains(): void
    {
        $domains = [$this->domain('a.example'), $this->domain('b.example')];
        $repository = $this->createMock(DomainRepository::class);
        $repository->expects($this->once())->method('findBy')->with([], ['name' => 'ASC'])->willReturn($domains);

        self::assertSame($domains, $this->policy($repository)->domainsForDnsValidation($this->actor(ActorRole::Admin, $domains[0])));
    }

    public function testDomainAdminValidatesDnsForTheirOwnDomain(): void
    {
        $domain = $this->domain();
        $repository = $this->createMock(DomainRepository::class);
        $repository->expects($this->never())->method('findBy');

        self::assertSame([$domain], $this->policy($repository)->domainsForDnsValidation($this->actor(ActorRole::DomainAdmin, $domain)));
    }

    public function testRegularUserValidatesDnsForNothing(): void
    {
        $repository = $this->createMock(DomainRepository::class);
        $repository->expects($this->never())->method('findBy');

        self::assertSame([], $this->policy($repository)->domainsForDnsValidation($this->actor(ActorRole::User, $this->domain())));
    }

    public function testDomainAdminWithoutDomainIsAnErrorForDnsValidation(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->policy()->domainsForDnsValidation(new Actor(new User(), ActorRole::DomainAdmin));
    }

    private function policy(?DomainRepository $repository = null): DomainPolicy
    {
        return new DomainPolicy($repository ?? $this->createStub(DomainRepository::class));
    }
}
