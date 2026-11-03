<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Helper;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Mcp\ToolDeniedException;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\LogRecord;

/**
 * Two domains with a user of every role, an alias and fetchmail accounts, for testing the management
 * tools over HTTP. Everything is rolled back after the test.
 */
trait ManagementToolsTrait
{
    use McpClientTrait;

    public const string ADMIN = 'admin@example.com';
    public const string DOMAIN_ADMIN = 'domainadmin@example.com';
    public const string REGULAR_USER = 'regular@example.com';
    public const string PEER = 'peer@example.com';
    public const string OWN_DOMAIN = 'example.com';
    public const string FOREIGN_DOMAIN = 'foreign.example.org';
    public const string FOREIGN_USER = 'stranger@foreign.example.org';
    public const string EMPTY_DOMAIN = 'empty.example.net';
    public const string STRONG_PASSWORD = 'Correct-Horse-Battery-5taple!';
    public const int MISSING_ID = 99999999;

    private int $ownAliasId;
    private int $foreignAliasId;
    private int $regularAccountId;
    private int $peerAccountId;
    private int $foreignAccountId;

    private function seedManagementData(): void
    {
        $entityManager = $this->entityManager();
        $ownDomain = $this->findUser(self::ADMIN)->getDomain();
        self::assertInstanceOf(Domain::class, $ownDomain);

        $foreignDomain = $this->newDomain(self::FOREIGN_DOMAIN);
        $this->newDomain(self::EMPTY_DOMAIN);

        $this->newUser('domainadmin', $ownDomain)->setDomainAdmin(true);
        $regular = $this->newUser('regular', $ownDomain);
        $peer = $this->newUser('peer', $ownDomain);
        $stranger = $this->newUser('stranger', $foreignDomain);

        $ownAlias = $this->newAlias('sales', $ownDomain);
        $foreignAlias = $this->newAlias('info', $foreignDomain);

        $regularAccount = $this->newAccount($regular, 'regular');
        $peerAccount = $this->newAccount($peer, 'peer');
        $foreignAccount = $this->newAccount($stranger, 'stranger');

        $entityManager->flush();

        $this->ownAliasId = (int) $ownAlias->getId();
        $this->foreignAliasId = (int) $foreignAlias->getId();
        $this->regularAccountId = (int) $regularAccount->getId();
        $this->peerAccountId = (int) $peerAccount->getId();
        $this->foreignAccountId = (int) $foreignAccount->getId();
    }

    private function newDomain(string $name): Domain
    {
        $domain = new Domain();
        $domain->setName($name);
        // Skips generating a key pair, which takes a while and is not what these tests are about.
        $domain->setDkimPrivateKey('not-a-real-key');
        $this->entityManager()->persist($domain);

        return $domain;
    }

    private function newUser(string $name, Domain $domain): User
    {
        $user = new User();
        $user->setName($name);
        $user->setDomain($domain);
        $user->setPassword('initial-hash');
        $this->entityManager()->persist($user);

        return $user;
    }

    private function newAlias(string $name, Domain $domain): Alias
    {
        $alias = new Alias();
        $alias->setName($name);
        $alias->setDomain($domain);
        $alias->setDestination('target@elsewhere.example.net');
        $this->entityManager()->persist($alias);

        return $alias;
    }

    private function newAccount(User $owner, string $username): FetchmailAccount
    {
        $account = new FetchmailAccount();
        $account->setUser($owner);
        $account->setHost('mail.elsewhere.example.net');
        $account->setUsername($username);
        $account->setPassword('sentinel-fetchmail-secret');
        $this->entityManager()->persist($account);

        return $account;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>} the JSON-RPC result
     */
    private function call(string $as, string $tool, array $arguments = []): array
    {
        $secret = $this->storeToken($this->findUser($as));

        return $this->callTool($secret, $this->initialiseSession($secret), $tool, $arguments);
    }

    /**
     * @param array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>} $result
     */
    private function assertToolDenied(array $result): void
    {
        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
    }

    /**
     * A refusal that explains itself, as opposed to the shared denial.
     *
     * @param array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>} $result
     */
    private function assertToolRejectedField(array $result, string $field): void
    {
        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));
        self::assertStringContainsString($field . ':', $result['content'][0]['text']);
    }

    private function lastAuditRecord(): LogRecord
    {
        $handler = $this->client->getContainer()->get('app.test.mcp_audit_records');

        $records = $handler->getRecords();
        self::assertNotEmpty($records);

        return end($records);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<mixed>
     */
    private function pageColumn(array $page, string $column): array
    {
        return array_column($this->pageItems($page), $column);
    }

    /**
     * @param array<string, mixed> $page
     *
     * @return list<array<string, mixed>>
     */
    private function pageItems(array $page): array
    {
        self::assertArrayHasKey('items', $page);
        self::assertIsArray($page['items']);

        /** @var list<array<string, mixed>> $items */
        $items = $page['items'];

        return $items;
    }
}
