<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Service\Security\Access;

use App\Entity\Domain;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\FetchmailAccountPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A domain admin must not change what an admin fetches: a new host would receive the
 * password that is kept when the form leaves it empty.
 */
class FetchmailAccountPolicyAdminOwnerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FetchmailAccountPolicy $policy;
    private Domain $domain;
    private User $domainAdmin;
    private User $admin;
    private User $regular;
    private FetchmailAccount $adminAccount;
    private FetchmailAccount $regularAccount;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->policy = static::getContainer()->get(FetchmailAccountPolicy::class);

        $this->domain = new Domain();
        $this->domain->setName('policy-admin-owner.invalid');
        $this->entityManager->persist($this->domain);

        $this->domainAdmin = $this->persistUser('boss', false);
        $this->admin = $this->persistUser('root', true);
        $this->regular = $this->persistUser('regular', false);
        $this->adminAccount = $this->persistAccount($this->admin);
        $this->regularAccount = $this->persistAccount($this->regular);

        $this->entityManager->flush();
    }

    public function testDomainAdminCannotManageDeleteOrAssignToAnAdminsAccount(): void
    {
        $actor = new Actor($this->domainAdmin, ActorRole::DomainAdmin);

        self::assertFalse($this->policy->canManage($actor, $this->adminAccount));
        self::assertFalse($this->policy->canDelete($actor, $this->adminAccount));
        self::assertFalse($this->policy->canAssignTo($actor, $this->admin));
    }

    public function testDomainAdminStillManagesTheAccountsOfRegularUsersInTheirDomain(): void
    {
        $actor = new Actor($this->domainAdmin, ActorRole::DomainAdmin);

        self::assertTrue($this->policy->canManage($actor, $this->regularAccount));
        self::assertTrue($this->policy->canDelete($actor, $this->regularAccount));
        self::assertTrue($this->policy->canAssignTo($actor, $this->regular));
    }

    public function testAdminManagesAnAdminsAccount(): void
    {
        $actor = new Actor($this->admin, ActorRole::Admin);

        self::assertTrue($this->policy->canManage($actor, $this->adminAccount));
        self::assertTrue($this->policy->canDelete($actor, $this->adminAccount));
        self::assertTrue($this->policy->canAssignTo($actor, $this->admin));
    }

    public function testAdminOwnersAreNotOfferedToADomainAdmin(): void
    {
        $actor = new Actor($this->domainAdmin, ActorRole::DomainAdmin);
        $qb = $this->entityManager->getRepository(User::class)->createQueryBuilder('entity');
        $this->policy->restrictToAssignableOwners($qb, $actor);
        $this->policy->excludeAdminOwners($qb, $actor);

        /** @var list<User> $owners */
        $owners = $qb->getQuery()->getResult();
        $names = array_map(static fn (User $user): string => $user->getName(), $owners);

        self::assertContains('regular', $names);
        self::assertNotContains('root', $names);
    }

    public function testAdminsAccountStaysVisibleToADomainAdmin(): void
    {
        $actor = new Actor($this->domainAdmin, ActorRole::DomainAdmin);
        $qb = $this->entityManager->getRepository(FetchmailAccount::class)->createQueryBuilder('entity');
        $this->policy->restrictToVisibleAccounts($qb, $actor);

        self::assertCount(2, $qb->getQuery()->getResult());
        self::assertTrue($this->policy->canView($actor, $this->adminAccount));
    }

    private function persistUser(string $name, bool $admin): User
    {
        $user = new User();
        $user->setDomain($this->domain);
        $user->setName($name);
        $user->setPlainPassword('password123');
        $user->setAdmin($admin);
        $this->entityManager->persist($user);

        return $user;
    }

    private function persistAccount(User $owner): FetchmailAccount
    {
        $account = new FetchmailAccount();
        $account->setUser($owner);
        $account->setHost($owner->getName() . '.example.net');
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('account');
        $account->setPassword('password');
        $this->entityManager->persist($account);

        return $account;
    }
}
