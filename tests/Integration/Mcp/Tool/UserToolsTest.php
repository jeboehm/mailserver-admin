<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Tool;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Tests\Integration\Helper\ManagementToolsTrait;

class UserToolsTest extends WebTestCase
{
    use ManagementToolsTrait;

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

    public function testAdminListsUsersOfAllDomains(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::ADMIN, 'user_list'));

        self::assertSame(5, $page['total']);
        self::assertContains(self::FOREIGN_USER, $this->pageColumn($page, 'address'));
    }

    public function testDomainAdminListsOnlyTheirDomain(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'user_list'));

        self::assertSame(4, $page['total']);
        self::assertNotContains(self::FOREIGN_USER, $this->pageColumn($page, 'address'));
    }

    public function testListIsPaginated(): void
    {
        $page = $this->assertToolSucceeded($this->call(self::ADMIN, 'user_list', ['limit' => 2, 'offset' => 4]));

        self::assertSame(5, $page['total']);
        self::assertSame([self::FOREIGN_USER], $this->pageColumn($page, 'address'));
    }

    public function testGetReturnsAUserWithoutAnyPassword(): void
    {
        $id = $this->userId(self::PEER);

        $result = $this->call(self::DOMAIN_ADMIN, 'user_get', ['id' => $id]);

        self::assertSame([
            'id' => $id,
            'address' => self::PEER,
            'name' => 'peer',
            'domain' => self::OWN_DOMAIN,
            'quota' => 0,
            'enabled' => true,
            'admin' => false,
            'domain_admin' => false,
            'send_only' => false,
        ], $this->assertToolSucceeded($result));
        self::assertStringNotContainsString('initial-hash', (string) json_encode($result));
    }

    public function testAdminGetsAUserOfAnyDomain(): void
    {
        $user = $this->assertToolSucceeded($this->call(self::ADMIN, 'user_get', ['id' => $this->userId(self::FOREIGN_USER)]));

        self::assertSame(self::FOREIGN_USER, $user['address']);
    }

    public function testForeignAndMissingUsersAnswerTheSame(): void
    {
        $foreign = $this->call(self::DOMAIN_ADMIN, 'user_get', ['id' => $this->userId(self::FOREIGN_USER)]);
        $missing = $this->call(self::DOMAIN_ADMIN, 'user_get', ['id' => self::MISSING_ID]);

        $this->assertToolDenied($foreign);
        self::assertSame($missing, $foreign);
    }

    public function testDomainAdminCreatesAUserInTheirDomainByDefault(): void
    {
        $result = $this->call(self::DOMAIN_ADMIN, 'user_create', [
            'name' => 'fresh',
            'password' => self::STRONG_PASSWORD,
            'quota' => 500,
            'send_only' => true,
            'domain_admin' => true,
        ]);

        $created = $this->assertToolSucceeded($result);
        self::assertSame('fresh@example.com', $created['address']);
        self::assertSame(500, $created['quota']);
        self::assertTrue($created['send_only']);
        self::assertTrue($created['domain_admin']);
        self::assertStringNotContainsString(self::STRONG_PASSWORD, (string) json_encode($result));

        $user = $this->findUser('fresh@example.com');
        self::assertTrue($this->passwordMatches($user, self::STRONG_PASSWORD));
    }

    public function testDomainAdminMayNameTheirOwnDomain(): void
    {
        $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'user_create', [
            'name' => 'fresh',
            'password' => self::STRONG_PASSWORD,
            'domain' => self::OWN_DOMAIN,
        ]));

        self::assertInstanceOf(User::class, $this->repository()->findOneByEmailAddress('fresh@example.com'));
    }

    public function testDomainAdminCannotChooseAnotherDomain(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'user_create', [
            'name' => 'fresh',
            'password' => self::STRONG_PASSWORD,
            'domain' => self::FOREIGN_DOMAIN,
        ]));

        self::assertNull($this->repository()->findOneByEmailAddress('fresh@' . self::FOREIGN_DOMAIN));
    }

    public function testChoosingAnUnknownDomainAnswersLikeAForeignOne(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'user_create', [
            'name' => 'fresh',
            'password' => self::STRONG_PASSWORD,
            'domain' => 'unknown.example.net',
        ]));
        $this->assertToolDenied($this->call(self::ADMIN, 'user_create', [
            'name' => 'fresh',
            'password' => self::STRONG_PASSWORD,
            'domain' => 'unknown.example.net',
        ]));
    }

    public function testDomainAdminCannotCreateAnAdmin(): void
    {
        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'user_create', [
            'name' => 'fresh',
            'password' => self::STRONG_PASSWORD,
            'admin' => true,
        ]));

        self::assertNull($this->repository()->findOneByEmailAddress('fresh@example.com'));
    }

    public function testAdminCreatesAnAdminInAnyDomain(): void
    {
        $created = $this->assertToolSucceeded($this->call(self::ADMIN, 'user_create', [
            'name' => 'fresh',
            'password' => self::STRONG_PASSWORD,
            'domain' => self::FOREIGN_DOMAIN,
            'admin' => true,
        ]));

        self::assertSame('fresh@' . self::FOREIGN_DOMAIN, $created['address']);
        self::assertTrue($created['admin']);
    }

    public function testCreateRejectsAWeakPasswordWithoutEchoingIt(): void
    {
        $result = $this->call(self::ADMIN, 'user_create', ['name' => 'fresh', 'password' => 'abc']);

        $this->assertToolRejectedField($result, 'password');
        self::assertStringNotContainsString('abc', $result['content'][0]['text']);
        self::assertNull($this->repository()->findOneByEmailAddress('fresh@example.com'));
    }

    public function testCatalogueWarnsThatThePasswordPassesThroughTheAgent(): void
    {
        $secret = $this->storeToken($this->findUser(self::ADMIN));

        $response = $this->mcpRequest('tools/list', [], 'Bearer ' . $secret, $this->initialiseSession($secret));
        $tools = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR)['result']['tools'];
        $tool = $tools[array_search('user_create', array_column($tools, 'name'), true)];

        self::assertContains('name', $tool['inputSchema']['required']);
        self::assertContains('password', $tool['inputSchema']['required']);
        self::assertStringContainsString('context of the agent', $tool['inputSchema']['properties']['password']['description']);
    }

    public function testPasswordOfTheWrongTypeIsNotEchoedEither(): void
    {
        $secret = $this->storeToken($this->findUser(self::ADMIN));

        $response = $this->mcpRequest('tools/call', [
            'name' => 'user_create',
            'arguments' => ['name' => 'fresh', 'password' => 918273645546],
        ], 'Bearer ' . $secret, $this->initialiseSession($secret));

        self::assertStringContainsString('password', (string) $response->getContent());
        self::assertStringNotContainsString('918273645546', (string) $response->getContent());
    }

    public function testCreateRejectsAnInvalidName(): void
    {
        $this->assertToolRejectedField($this->call(self::ADMIN, 'user_create', [
            'name' => 'Not Valid',
            'password' => self::STRONG_PASSWORD,
        ]), 'name');
    }

    public function testCreateRejectsAnExistingAddress(): void
    {
        $result = $this->call(self::DOMAIN_ADMIN, 'user_create', ['name' => 'peer', 'password' => self::STRONG_PASSWORD]);

        self::assertTrue($result['isError']);
        self::assertStringNotContainsString(self::STRONG_PASSWORD, (string) json_encode($result));
    }

    public function testUpdateChangesOnlyWhatIsPassed(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'user_update', [
            'id' => $this->userId(self::PEER),
            'quota' => 750,
            'send_only' => true,
        ]));

        self::assertSame(750, $updated['quota']);
        self::assertTrue($updated['send_only']);
        self::assertTrue($updated['enabled']);
        self::assertSame('initial-hash', $this->findUser(self::PEER)->getPassword());
    }

    public function testUpdateSetsANewPasswordWithoutEchoingIt(): void
    {
        $result = $this->call(self::DOMAIN_ADMIN, 'user_update', [
            'id' => $this->userId(self::PEER),
            'password' => self::STRONG_PASSWORD,
        ]);

        $this->assertToolSucceeded($result);
        self::assertStringNotContainsString(self::STRONG_PASSWORD, (string) json_encode($result));
        self::assertTrue($this->passwordMatches($this->findUser(self::PEER), self::STRONG_PASSWORD));
    }

    public function testUpdateSetsAPasswordForAUserWhoHasNone(): void
    {
        // What a first OIDC login leaves behind.
        $user = $this->findUser(self::PEER);
        $user->setPassword('');
        $this->entityManager()->flush();

        $this->assertToolSucceeded($this->call(self::ADMIN, 'user_update', [
            'id' => $user->getId(),
            'password' => self::STRONG_PASSWORD,
        ]));

        self::assertTrue($this->passwordMatches($this->findUser(self::PEER), self::STRONG_PASSWORD));
    }

    public function testUpdateRejectsAWeakPasswordAndKeepsTheOldOne(): void
    {
        $result = $this->call(self::ADMIN, 'user_update', ['id' => $this->userId(self::PEER), 'password' => 'abc', 'quota' => 750]);

        $this->assertToolRejectedField($result, 'password');
        self::assertStringNotContainsString('abc', $result['content'][0]['text']);

        $this->entityManager()->clear();
        $peer = $this->findUser(self::PEER);
        self::assertSame('initial-hash', $peer->getPassword());
        self::assertSame(0, $peer->getQuota());
    }

    public function testUpdateRejectsAnEmptyPassword(): void
    {
        $this->assertToolRejectedField($this->call(self::ADMIN, 'user_update', ['id' => $this->userId(self::PEER), 'password' => '']), 'password');
    }

    public function testDomainAdminCannotUpdateAForeignUser(): void
    {
        $id = $this->userId(self::FOREIGN_USER);

        $foreign = $this->call(self::DOMAIN_ADMIN, 'user_update', ['id' => $id, 'quota' => 750, 'password' => self::STRONG_PASSWORD]);
        $missing = $this->call(self::DOMAIN_ADMIN, 'user_update', ['id' => self::MISSING_ID, 'quota' => 750, 'password' => self::STRONG_PASSWORD]);

        $this->assertToolDenied($foreign);
        self::assertSame($missing, $foreign);

        $this->entityManager()->clear();
        self::assertSame(0, $this->findUser(self::FOREIGN_USER)->getQuota());
    }

    public function testDomainAdminCannotTouchTheAdminFlag(): void
    {
        foreach ([true, false] as $value) {
            $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'user_update', ['id' => $this->userId(self::PEER), 'admin' => $value, 'quota' => 750]));
        }

        $this->entityManager()->clear();
        $peer = $this->findUser(self::PEER);
        self::assertFalse($peer->isAdmin());
        self::assertSame(0, $peer->getQuota());
    }

    public function testAdminSetsTheAdminFlag(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::ADMIN, 'user_update', ['id' => $this->userId(self::PEER), 'admin' => true]));

        self::assertTrue($updated['admin']);
    }

    public function testUpdateCannotMoveAUserToAnotherDomainOrRenameThem(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::ADMIN, 'user_update', [
            'id' => $this->userId(self::PEER),
            'domain' => self::FOREIGN_DOMAIN,
            'name' => 'renamed',
        ]));

        self::assertSame(self::PEER, $updated['address']);
    }

    public function testSelfUpdateKeepsOwnRoleAndEnabledState(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::ADMIN, 'user_update', [
            'id' => $this->userId(self::ADMIN),
            'enabled' => false,
            'admin' => false,
            'quota' => 750,
        ]));

        self::assertTrue($updated['enabled']);
        self::assertTrue($updated['admin']);
        self::assertSame(750, $updated['quota']);
    }

    public function testDomainAdminCannotDropOwnRole(): void
    {
        $updated = $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'user_update', [
            'id' => $this->userId(self::DOMAIN_ADMIN),
            'domain_admin' => false,
            'enabled' => false,
        ]));

        self::assertTrue($updated['domain_admin']);
        self::assertTrue($updated['enabled']);
    }

    public function testChangingOwnPasswordRevokesTheTokenUsed(): void
    {
        $secret = $this->storeToken($this->findUser(self::DOMAIN_ADMIN));
        $sessionId = $this->initialiseSession($secret);

        $this->assertToolSucceeded($this->callTool($secret, $sessionId, 'user_update', [
            'id' => $this->userId(self::DOMAIN_ADMIN),
            'password' => self::STRONG_PASSWORD,
        ]));

        $response = $this->mcpRequest('tools/call', ['name' => 'whoami'], 'Bearer ' . $secret, $sessionId);
        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testDeleteRemovesAUserOfOwnDomain(): void
    {
        $id = $this->userId(self::PEER);

        self::assertSame(['deleted' => true], $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'user_delete', ['id' => $id])));
        self::assertNull($this->repository()->find($id));
    }

    public function testAdminDeletesAUserOfAnyDomain(): void
    {
        $id = $this->userId(self::FOREIGN_USER);

        $this->assertToolSucceeded($this->call(self::ADMIN, 'user_delete', ['id' => $id]));

        self::assertNull($this->repository()->find($id));
    }

    public function testNobodyDeletesThemselves(): void
    {
        foreach ([self::ADMIN, self::DOMAIN_ADMIN] as $address) {
            $this->assertToolDenied($this->call($address, 'user_delete', ['id' => $this->userId($address)]));
            self::assertInstanceOf(User::class, $this->repository()->findOneByEmailAddress($address));
        }
    }

    public function testForeignAndMissingUsersCannotBeDeletedAndAnswerTheSame(): void
    {
        $id = $this->userId(self::FOREIGN_USER);

        $foreign = $this->call(self::DOMAIN_ADMIN, 'user_delete', ['id' => $id]);
        $missing = $this->call(self::DOMAIN_ADMIN, 'user_delete', ['id' => self::MISSING_ID]);

        $this->assertToolDenied($foreign);
        self::assertSame($missing, $foreign);
        self::assertInstanceOf(User::class, $this->repository()->find($id));
    }

    public function testDeletingAUserTakesTheirTokensAlong(): void
    {
        $peer = $this->findUser(self::PEER);
        $id = $peer->getId();
        $secret = $this->storeToken($peer);

        $this->assertToolSucceeded($this->call(self::ADMIN, 'user_delete', ['id' => $id]));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->mcpRequest('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ], 'Bearer ' . $secret)->getStatusCode());
    }

    public function testAuditRecordNamesTheTargetAndLeavesOutThePassword(): void
    {
        $this->assertToolSucceeded($this->call(self::DOMAIN_ADMIN, 'user_update', [
            'id' => $this->userId(self::PEER),
            'password' => self::STRONG_PASSWORD,
        ]));

        $record = $this->lastAuditRecord();
        self::assertSame('user_update', $record->context['tool']);
        self::assertSame('ok', $record->context['result']);
        self::assertSame(\sprintf('user:%d', $this->userId(self::PEER)), $record->context['target']);
        self::assertStringNotContainsString(self::STRONG_PASSWORD, (string) json_encode($record->context));
    }

    public function testDeniedAccessToAForeignUserIsAuditedWithItsTarget(): void
    {
        $id = $this->userId(self::FOREIGN_USER);

        $this->assertToolDenied($this->call(self::DOMAIN_ADMIN, 'user_get', ['id' => $id]));

        $record = $this->lastAuditRecord();
        self::assertSame('denied', $record->context['result']);
        self::assertSame(\sprintf('user:%d', $id), $record->context['target']);
    }

    private function userId(string $address): int
    {
        return (int) $this->findUser($address)->getId();
    }

    private function repository(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }

    private function passwordMatches(User $user, string $password): bool
    {
        return static::getContainer()->get(PasswordHasherFactoryInterface::class)
            ->getPasswordHasher($user)
            ->verify($user->getPassword(), $password);
    }
}
