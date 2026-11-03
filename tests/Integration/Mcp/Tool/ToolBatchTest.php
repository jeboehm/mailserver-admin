<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Tool;

use App\Entity\Alias;
use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Tests\Integration\Helper\ManagementToolsTrait;

/**
 * A JSON-RPC batch runs in one PHP request, so its calls share one entity manager: whatever a rejected
 * call left on a managed entity would be saved by the next call's flush.
 */
class ToolBatchTest extends WebTestCase
{
    use ManagementToolsTrait;

    private const array NEXT_WRITE = ['alias_create', ['name' => 'after', 'destination' => 'target@elsewhere.example.net']];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();
        $this->seedManagementData();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');

        parent::tearDown();
    }

    public function testRejectedUserUpdateIsNotSavedByTheNextWrite(): void
    {
        $results = $this->batch(self::ADMIN, [
            ['user_update', ['id' => $this->userId(self::PEER), 'quota' => -1, 'password' => 'abc']],
            self::NEXT_WRITE,
        ]);

        self::assertTrue($results[0]['isError'], (string) json_encode($results[0]));
        $this->assertToolSucceeded($results[1]);

        $this->entityManager()->clear();
        $peer = $this->findUser(self::PEER);
        self::assertSame(0, $peer->getQuota());
        self::assertSame('initial-hash', $peer->getPassword());
    }

    public function testRejectedPasswordIsNotHashedByALaterUpdateOfThatUser(): void
    {
        $id = $this->userId(self::PEER);

        $results = $this->batch(self::ADMIN, [
            ['user_update', ['id' => $id, 'password' => 'abc']],
            ['user_update', ['id' => $id, 'quota' => 750]],
        ]);

        self::assertTrue($results[0]['isError'], (string) json_encode($results[0]));
        $this->assertToolSucceeded($results[1]);

        $this->entityManager()->clear();
        $peer = $this->findUser(self::PEER);
        self::assertSame(750, $peer->getQuota());
        self::assertSame('initial-hash', $peer->getPassword());
    }

    public function testRejectedPasswordDoesNotRevokeTokensOnALaterUpdate(): void
    {
        $peer = $this->findUser(self::PEER);
        $this->storeToken($peer);
        $id = (int) $peer->getId();

        $this->batch(self::ADMIN, [
            ['user_update', ['id' => $id, 'password' => 'abc']],
            ['user_update', ['id' => $id, 'quota' => 750]],
        ]);

        $this->entityManager()->clear();
        $token = $this->entityManager()->getRepository(ApiToken::class)->findOneBy(['owner' => $id]);
        self::assertInstanceOf(ApiToken::class, $token);
        self::assertFalse($token->isRevoked());
    }

    public function testRejectedUpdateOfOneselfLeavesTheActorIntact(): void
    {
        $id = $this->userId(self::ADMIN);

        $results = $this->batch(self::ADMIN, [
            ['user_update', ['id' => $id, 'quota' => -1, 'password' => 'abc']],
            ['fetchmail_create', ['host' => 'mail.after.example.net', 'username' => 'after', 'password' => 'secret']],
        ]);

        self::assertTrue($results[0]['isError'], (string) json_encode($results[0]));
        self::assertSame(self::ADMIN, $this->assertToolSucceeded($results[1])['owner']);

        $this->entityManager()->clear();
        $admin = $this->findUser(self::ADMIN);
        self::assertSame(0, $admin->getQuota());
        self::assertFalse($this->passwordMatches($admin, 'abc'));
    }

    public function testACallAfterARejectedUpdateReadsWhatIsStored(): void
    {
        $id = $this->userId(self::PEER);

        $results = $this->batch(self::ADMIN, [
            ['user_update', ['id' => $id, 'quota' => -1]],
            ['user_get', ['id' => $id]],
        ]);

        self::assertSame(0, $this->assertToolSucceeded($results[1])['quota']);
    }

    public function testRejectedFetchmailUpdateIsNotSavedByTheNextWrite(): void
    {
        $results = $this->batch(self::ADMIN, [
            ['fetchmail_update', ['id' => $this->peerAccountId, 'port' => 70000, 'password' => 'leftover-secret', 'owner' => self::REGULAR_USER]],
            self::NEXT_WRITE,
        ]);

        self::assertTrue($results[0]['isError'], (string) json_encode($results[0]));
        $this->assertToolSucceeded($results[1]);

        $this->entityManager()->clear();
        $account = $this->entityManager()->find(FetchmailAccount::class, $this->peerAccountId);
        self::assertInstanceOf(FetchmailAccount::class, $account);
        self::assertSame(143, $account->getPort());
        self::assertSame('sentinel-fetchmail-secret', $account->getPassword());
        self::assertSame(self::PEER, $account->getUser()?->getUserIdentifier());
    }

    public function testRejectedAliasUpdateIsNotSavedByTheNextWrite(): void
    {
        $results = $this->batch(self::ADMIN, [
            ['alias_update', ['id' => $this->ownAliasId, 'name' => 'NOT VALID', 'destination' => 'not-an-address']],
            self::NEXT_WRITE,
        ]);

        self::assertTrue($results[0]['isError'], (string) json_encode($results[0]));
        $this->assertToolSucceeded($results[1]);

        $this->entityManager()->clear();
        $alias = $this->entityManager()->find(Alias::class, $this->ownAliasId);
        self::assertInstanceOf(Alias::class, $alias);
        self::assertSame('sales', $alias->getName());
        self::assertSame('target@elsewhere.example.net', $alias->getDestination());
    }

    public function testRejectedDkimChangeIsNotSavedByTheNextWrite(): void
    {
        // Validating a domain includes its users, so one stored invalid user makes dkim_enable fail.
        $domain = $this->entityManager()->getRepository(Domain::class)->findOneBy(['name' => self::EMPTY_DOMAIN]);
        self::assertInstanceOf(Domain::class, $domain);
        $this->newUser('invalid', $domain)->setQuota(-1);
        $this->entityManager()->flush();

        $results = $this->batch(self::ADMIN, [
            ['dkim_enable', ['domain' => self::EMPTY_DOMAIN]],
            self::NEXT_WRITE,
        ]);

        self::assertTrue($results[0]['isError'], (string) json_encode($results[0]));
        $this->assertToolSucceeded($results[1]);

        $this->entityManager()->clear();
        $domain = $this->entityManager()->getRepository(Domain::class)->findOneBy(['name' => self::EMPTY_DOMAIN]);
        self::assertInstanceOf(Domain::class, $domain);
        self::assertFalse($domain->getDkimEnabled());
    }

    public function testRejectedCreateIsNotSavedByTheNextWrite(): void
    {
        $results = $this->batch(self::ADMIN, [
            ['user_create', ['name' => 'rejected', 'password' => 'abc']],
            self::NEXT_WRITE,
        ]);

        self::assertTrue($results[0]['isError'], (string) json_encode($results[0]));
        $this->assertToolSucceeded($results[1]);

        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->getRepository(User::class)->findOneBy(['name' => 'rejected']));
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>}> $calls
     *
     * @return list<array<string, mixed>>
     */
    private function batch(string $as, array $calls): array
    {
        $secret = $this->storeToken($this->findUser($as));

        return $this->callToolsInBatch($secret, $this->initialiseSession($secret), $calls);
    }

    private function userId(string $address): int
    {
        return (int) $this->findUser($address)->getId();
    }

    private function passwordMatches(User $user, string $password): bool
    {
        return static::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher($user)->verify((string) $user->getPassword(), $password);
    }
}
