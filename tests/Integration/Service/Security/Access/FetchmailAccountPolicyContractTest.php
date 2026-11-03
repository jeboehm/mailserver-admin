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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The owner rule has a query form for lists and forms, and a per-object form for
 * single decisions. Both have to pick the same rows.
 */
class FetchmailAccountPolicyContractTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private FetchmailAccountPolicy $policy;
    private User $actorUser;

    #[\Override]
    protected function setUp(): void
    {
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->policy = static::getContainer()->get(FetchmailAccountPolicy::class);

        $ownDomain = $this->persistDomain('own.invalid');
        $foreignDomain = $this->persistDomain('foreign.invalid');

        $this->actorUser = $this->persistUser($ownDomain, 'actor');
        $neighbour = $this->persistUser($ownDomain, 'neighbour');
        $foreigner = $this->persistUser($foreignDomain, 'foreigner');

        $this->persistAccount($this->actorUser, 'actor.example.net');
        $this->persistAccount($neighbour, 'neighbour.example.net');
        $this->persistAccount($foreigner, 'foreigner.example.net');

        $this->entityManager->flush();
    }

    /**
     * @return iterable<string, array{ActorRole, list<string>, list<string>}>
     */
    public static function scopes(): iterable
    {
        yield 'admin' => [ActorRole::Admin, ['actor', 'neighbour', 'foreigner'], []];
        yield 'domain admin' => [ActorRole::DomainAdmin, ['actor', 'neighbour'], ['foreigner']];
        yield 'regular user' => [ActorRole::User, ['actor'], ['neighbour', 'foreigner']];
    }

    /**
     * @param list<string> $included
     * @param list<string> $excluded
     */
    #[DataProvider('scopes')]
    public function testAssignableOwnersMatchTheOwnersThatCanBeAssigned(ActorRole $role, array $included, array $excluded): void
    {
        $actor = new Actor($this->actorUser, $role);
        $qb = $this->entityManager->getRepository(User::class)->createQueryBuilder('entity');
        $this->policy->restrictToAssignableOwners($qb, $actor);

        /** @var list<User> $fromQuery */
        $fromQuery = $qb->getQuery()->getResult();
        $fromRule = array_filter(
            $this->entityManager->getRepository(User::class)->findAll(),
            fn (User $user): bool => $this->policy->canAssignTo($actor, $user)
        );

        self::assertEqualsCanonicalizing($this->names($fromRule), $this->names($fromQuery));
        self::assertEqualsCanonicalizing($included, array_values(array_intersect($this->names($fromQuery), [...$included, ...$excluded])));
    }

    /**
     * @param list<string> $included
     * @param list<string> $excluded
     */
    #[DataProvider('scopes')]
    public function testVisibleAccountsMatchTheAccountsThatCanBeDeleted(ActorRole $role, array $included, array $excluded): void
    {
        $actor = new Actor($this->actorUser, $role);
        $qb = $this->entityManager->getRepository(FetchmailAccount::class)->createQueryBuilder('entity');
        $this->policy->restrictToVisibleAccounts($qb, $actor);

        /** @var list<FetchmailAccount> $fromQuery */
        $fromQuery = $qb->getQuery()->getResult();
        $fromRule = array_filter(
            $this->entityManager->getRepository(FetchmailAccount::class)->findAll(),
            fn (FetchmailAccount $account): bool => $this->policy->canDelete($actor, $account)
        );

        $expected = array_map(static fn (string $name): string => $name . '.example.net', $included);
        $unexpected = array_map(static fn (string $name): string => $name . '.example.net', $excluded);

        self::assertEqualsCanonicalizing($this->hosts($fromRule), $this->hosts($fromQuery));
        self::assertEqualsCanonicalizing($expected, array_values(array_intersect($this->hosts($fromQuery), [...$expected, ...$unexpected])));
    }

    /**
     * @param array<User> $users
     *
     * @return list<string>
     */
    private function names(array $users): array
    {
        return array_map(static fn (User $user): string => $user->getName(), array_values($users));
    }

    private function persistDomain(string $name): Domain
    {
        $domain = new Domain();
        $domain->setName($name);
        $this->entityManager->persist($domain);

        return $domain;
    }

    private function persistUser(Domain $domain, string $name): User
    {
        $user = new User();
        $user->setDomain($domain);
        $user->setName($name);
        $user->setPlainPassword('password123');
        $this->entityManager->persist($user);

        return $user;
    }

    private function persistAccount(User $owner, string $host): void
    {
        $account = new FetchmailAccount();
        $account->setUser($owner);
        $account->setHost($host);
        $account->setPort(143);
        $account->setProtocol('imap');
        $account->setUsername('account');
        $account->setPassword('password');
        $this->entityManager->persist($account);
    }

    /**
     * @param array<FetchmailAccount> $accounts
     *
     * @return list<string>
     */
    private function hosts(array $accounts): array
    {
        return array_map(static fn (FetchmailAccount $account): string => $account->getHost(), array_values($accounts));
    }
}
