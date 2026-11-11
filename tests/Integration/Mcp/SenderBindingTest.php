<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\Alias;
use App\Entity\Domain;
use App\Entity\User;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\ApiToken\SenderMode;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\FakeImapServer;
use Tests\Integration\Helper\FakeSmtpServer;
use Tests\Integration\Helper\McpClientTrait;

/**
 * The sender a mail:send or mail:draft token is bound to, over JSON-RPC for message_send and
 * message_draft, with the ownership rules driven by real alias data.
 */
class SenderBindingTest extends WebTestCase
{
    use McpClientTrait;

    private const string SEND = 'message_send';
    private const string DRAFT = 'message_draft';

    private const array LIMIT_VARIABLES = [
        'MCP_MAIL_SEND_TOKEN_LIMIT_PER_HOUR',
        'MCP_MAIL_DRAFT_TOKEN_LIMIT_PER_HOUR',
    ];

    private const array SAVED = [
        'TAG1 OK Logged in',
        '* LIST (\HasNoChildren \Sent \Drafts) "." Saved',
        'TAG2 OK List completed',
        '+ Ready for literal',
        'TAG3 OK [APPENDUID 1 9] Append completed',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->setEnvironment('MCP_MAIL_SEND_ENABLED', 'true');
        $this->client = static::createClient();
        static::getContainer()->get('app.mail_send_limit_pool')->clear();
        $this->servers();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FakeSmtpServer::$current = null;
        FakeImapServer::$current = null;
        $this->unsetEnvironment('MCP_ENABLED');
        $this->unsetEnvironment('MCP_MAIL_SEND_ENABLED');

        foreach (self::LIMIT_VARIABLES as $variable) {
            $this->unsetEnvironment($variable);
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tools(): iterable
    {
        yield 'message_send' => [self::SEND];
        yield 'message_draft' => [self::DRAFT];
    }

    #[DataProvider('tools')]
    public function testTheSchemaHasAnOptionalFromAddressAndIsTheSameForEveryToken(string $tool): void
    {
        $this->alias('info', 'example.com', 'admin@example.com');
        $primary = $this->token(SenderMode::Primary);
        $fixed = $this->token(SenderMode::Fixed, 'info@example.com');

        $schema = $this->toolDefinition($primary, $this->initialiseSession($primary), $tool)['inputSchema'];

        self::assertSame(['to', 'subject', 'body', 'cc', 'bcc', 'from_address'], array_keys($schema['properties'] ?? []));
        self::assertNotContains('from_address', $schema['required'] ?? []);
        self::assertSame($schema, $this->toolDefinition($fixed, $this->initialiseSession($fixed), $tool)['inputSchema']);
    }

    /**
     * @return iterable<string, array{string, SenderMode, string|null, string}>
     */
    public static function boundTokensGivenAFromAddress(): iterable
    {
        foreach (self::tools() as $name => [$tool]) {
            yield $name . ', primary, the primary address' => [$tool, SenderMode::Primary, null, 'admin@example.com'];
            yield $name . ', primary, another address' => [$tool, SenderMode::Primary, null, 'info@example.com'];
            yield $name . ', fixed, the bound address' => [$tool, SenderMode::Fixed, 'info@example.com', 'info@example.com'];
            yield $name . ', fixed, another address' => [$tool, SenderMode::Fixed, 'info@example.com', 'admin@example.com'];
        }
    }

    #[DataProvider('boundTokensGivenAFromAddress')]
    public function testABoundTokenRefusesFromAddressEvenWhenItMatches(string $tool, SenderMode $mode, ?string $bound, string $fromAddress): void
    {
        $this->alias('info', 'example.com', 'admin@example.com');

        $error = $this->callExpectingError($this->token($mode, $bound), $tool, ['from_address' => $fromAddress]);

        self::assertStringContainsString('from_address', $error);
        $this->assertNothingHappened();
    }

    public function testAFixedTokenSendsAsItsAddressEverywhere(): void
    {
        $this->domain('example.org');
        $this->alias('sales', 'example.org', 'admin@example.com');

        $result = $this->call($this->token(SenderMode::Fixed, 'sales@example.org'), self::SEND);

        $smtp = FakeSmtpServer::$current;
        self::assertInstanceOf(FakeSmtpServer::class, $smtp);
        self::assertSame('sales@example.org', $result['from']);
        self::assertSame(['cred#admin@example.com'], $smtp->logins, 'The login stays the owner.');
        self::assertSame(['sales@example.org'], $smtp->mailFrom);
        self::assertStringContainsString("From: sales@example.org\r\n", $smtp->data);
        self::assertIsString($result['message_id']);
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.org>$/', $result['message_id']);
        self::assertStringContainsString("From: sales@example.org\r\n", FakeImapServer::$current?->sent() ?? '');
        self::assertStringContainsString('Message-ID: ' . $result['message_id'], FakeImapServer::$current?->sent() ?? '');
    }

    public function testAFixedTokenDraftsAsItsAddress(): void
    {
        $this->domain('example.org');
        $this->alias('sales', 'example.org', 'admin@example.com');

        $result = $this->call($this->token(SenderMode::Fixed, 'sales@example.org'), self::DRAFT);

        self::assertSame('sales@example.org', $result['from']);
        self::assertIsString($result['message_id']);
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.org>$/', $result['message_id']);
        self::assertStringContainsString("From: sales@example.org\r\n", FakeImapServer::$current?->sent() ?? '');
        self::assertSame(0, FakeSmtpServer::$current?->connections);
    }

    #[DataProvider('tools')]
    public function testAFixedTokenMayBeBoundToThePrimaryAddress(string $tool): void
    {
        $result = $this->call($this->token(SenderMode::Fixed, 'admin@example.com'), $tool);

        self::assertSame('admin@example.com', $result['from']);
    }

    #[DataProvider('tools')]
    public function testAPrimaryTokenKeepsSendingAsThePrimaryAddress(string $tool): void
    {
        $this->alias('info', 'example.com', 'admin@example.com');

        self::assertSame('admin@example.com', $this->call($this->token(SenderMode::Primary), $tool)['from']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function limitedTools(): iterable
    {
        yield 'message_send' => [self::SEND, 'MCP_MAIL_SEND_TOKEN_LIMIT_PER_HOUR'];
        yield 'message_draft' => [self::DRAFT, 'MCP_MAIL_DRAFT_TOKEN_LIMIT_PER_HOUR'];
    }

    #[DataProvider('limitedTools')]
    public function testADeletedAliasFailsCleanlyAndTheTokenWorksAgainOnceItIsBack(string $tool, string $limit): void
    {
        $this->setEnvironment($limit, '1');
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->servers();

        $this->alias('info', 'example.com', 'admin@example.com');
        $secret = $this->token(SenderMode::Fixed, 'info@example.com');
        $this->removeAlias('info', 'example.com');

        $error = $this->callExpectingError($secret, $tool);

        self::assertStringContainsString('info@example.com', $error);
        self::assertStringContainsString('no longer', $error);
        $this->assertNothingHappened();
        self::assertSame(0, $this->shortLivedCredentialCount());

        $this->alias('info', 'example.com', 'admin@example.com');

        self::assertSame('info@example.com', $this->call($secret, $tool)['from'], 'Neither the token nor its rate limit is spent.');
    }

    public function testARepointedAliasIsNoLongerOwned(): void
    {
        $this->createUser('peer');
        $this->alias('info', 'example.com', 'admin@example.com');
        $secret = $this->token(SenderMode::Fixed, 'info@example.com');

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $alias = $entityManager->getRepository(Alias::class)->findOneBy(['name' => 'info']);
        self::assertInstanceOf(Alias::class, $alias);
        $alias->setDestination('peer@example.com');
        $entityManager->flush();

        self::assertStringContainsString('no longer', $this->callExpectingError($secret, self::SEND));
        $this->assertNothingHappened();
    }

    /**
     * What the user owns, as Postfix's email-submission query sees it, narrowed: the address of a
     * fixed token works only if it is owned.
     *
     * @return iterable<string, array{list<array{string, string, string}>, string, bool}>
     */
    public static function ownership(): iterable
    {
        yield 'an alias of the primary address' => [[['info', 'example.com', 'admin@example.com']], 'info@example.com', true];
        yield 'an alias, bound in mixed case' => [[['info', 'example.com', 'admin@example.com']], 'Info@Example.COM', true];
        yield 'an alias with a destination in mixed case' => [[['info', 'example.com', 'Admin@Example.com']], 'info@example.com', true];
        yield 'an alias stored in upper case' => [[['Info', 'example.com', 'admin@example.com']], 'info@example.com', false];
        yield 'an alias with several destinations, one of them the user' => [
            [['team', 'example.com', 'bob@external.test'], ['team', 'example.com', 'admin@example.com']],
            'team@example.com',
            true,
        ];
        yield 'an alias with an external destination' => [[['ext', 'example.com', 'bob@external.test']], 'ext@example.com', false];
        yield 'an alias of another user' => [[['peer-alias', 'example.com', 'peer@example.com']], 'peer-alias@example.com', false];
        yield 'another user\'s primary address' => [[], 'peer@example.com', false];
        yield 'an address a catch-all delivers to the user' => [[['', 'example.com', 'admin@example.com']], 'anything@example.com', false];
        yield 'an address extension' => [[], 'admin-tag@example.com', false];
        yield 'the end of an alias chain' => [
            [['first', 'example.com', 'second@example.com'], ['second', 'example.com', 'admin@example.com']],
            'second@example.com',
            true,
        ];
        yield 'the start of an alias chain' => [
            [['first', 'example.com', 'second@example.com'], ['second', 'example.com', 'admin@example.com']],
            'first@example.com',
            false,
        ];
    }

    /**
     * @param list<array{string, string, string}> $aliases name, domain and destination
     */
    #[DataProvider('ownership')]
    public function testOnlyOwnedAddressesCanBeUsed(array $aliases, string $bound, bool $owned): void
    {
        $this->createUser('peer');

        foreach ($aliases as [$name, $domain, $destination]) {
            $this->alias($name, $domain, $destination);
        }

        $secret = $this->token(SenderMode::Fixed, $bound);

        if ($owned) {
            self::assertSame(mb_strtolower($bound), $this->call($secret, self::SEND)['from']);

            return;
        }

        self::assertStringContainsString('no longer', $this->callExpectingError($secret, self::SEND));
        $this->assertNothingHappened();
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function roles(): iterable
    {
        yield 'admin' => [true, false];
        yield 'domain admin' => [false, true];
    }

    #[DataProvider('roles')]
    public function testNoRoleOwnsMoreThanItsOwnAddresses(bool $admin, bool $domainAdmin): void
    {
        $actor = $this->createUser('actor');
        $actor->setAdmin($admin);
        $actor->setDomainAdmin($domainAdmin);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->alias('actor-alias', 'example.com', 'actor@example.com');
        $this->alias('peer-alias', 'example.com', 'admin@example.com');

        foreach (['admin@example.com', 'peer-alias@example.com'] as $foreign) {
            $secret = $this->token(SenderMode::Fixed, $foreign, 'actor@example.com');
            self::assertStringContainsString('no longer', $this->callExpectingError($secret, self::SEND));
        }

        $this->assertNothingHappened();
        self::assertSame('actor-alias@example.com', $this->call($this->token(SenderMode::Fixed, 'actor-alias@example.com', 'actor@example.com'), self::SEND)['from']);
    }

    #[DataProvider('tools')]
    public function testTheAuditRecordNamesTheSender(string $tool): void
    {
        $this->alias('info', 'example.com', 'admin@example.com');

        $this->call($this->token(SenderMode::Fixed, 'info@example.com'), $tool, ['body' => 'the secret plan']);

        $handler = static::getContainer()->get('app.test.mcp_audit_records');
        $records = array_values(array_filter($handler->getRecords(), static fn (LogRecord $record): bool => $tool === ($record->context['tool'] ?? null)));
        self::assertCount(1, $records);
        self::assertSame('info@example.com', $records[0]->context['from']);
        self::assertStringNotContainsString('secret plan', (string) json_encode($records[0]->toArray()));
    }

    /**
     * @return iterable<string, array{list<ApiTokenScope>, SenderMode, string|null, array<string, string>|null}>
     */
    public static function whoami(): iterable
    {
        yield 'send, primary' => [[ApiTokenScope::MailSend], SenderMode::Primary, null, ['mode' => 'primary']];
        yield 'draft, fixed' => [[ApiTokenScope::MailDraft], SenderMode::Fixed, 'info@example.com', ['mode' => 'fixed', 'address' => 'info@example.com']];
        yield 'no mail scope' => [[ApiTokenScope::ManageRead, ApiTokenScope::MailRead], SenderMode::Primary, null, null];
    }

    /**
     * @param list<ApiTokenScope>        $scopes
     * @param array<string, string>|null $expected
     */
    #[DataProvider('whoami')]
    public function testWhoamiTellsTheSenderOfAMailToken(array $scopes, SenderMode $mode, ?string $bound, ?array $expected): void
    {
        $this->alias('info', 'example.com', 'admin@example.com');
        $secret = $this->storeToken($this->findUser(), $scopes, senderMode: $mode, senderAddress: $bound);

        $output = $this->assertToolSucceeded($this->callTool($secret, $this->initialiseSession($secret), 'whoami'));

        if (null === $expected) {
            self::assertArrayNotHasKey('send_as', $output);

            return;
        }

        self::assertSame($expected, $output['send_as'] ?? null);
    }

    private function token(SenderMode $mode, ?string $address = null, string $owner = 'admin@example.com'): string
    {
        return $this->storeToken($this->findUser($owner), [ApiTokenScope::MailSend, ApiTokenScope::MailDraft], senderMode: $mode, senderAddress: $address);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $secret, string $tool, array $arguments = []): array
    {
        $this->servers();

        return $this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), $tool, [...$this->mail(), ...$arguments]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callExpectingError(string $secret, string $tool, array $arguments = []): string
    {
        $this->servers();
        $result = $this->callTool($secret, $this->initialiseSession($secret), $tool, [...$this->mail(), ...$arguments]);

        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));

        return $result['content'][0]['text'];
    }

    private function assertNothingHappened(): void
    {
        self::assertSame(0, FakeSmtpServer::$current?->connections);
        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    private function servers(): void
    {
        FakeImapServer::$current = new FakeImapServer(self::SAVED);
        FakeSmtpServer::$current = new FakeSmtpServer([]);
    }

    /**
     * @return array{to: list<string>, subject: string, body: string}
     */
    private function mail(): array
    {
        return ['to' => ['bob@example.com'], 'subject' => 'Hello', 'body' => 'Hello Bob'];
    }

    private function domain(string $name): Domain
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $domain = $entityManager->getRepository(Domain::class)->findOneBy(['name' => $name]);

        if (null === $domain) {
            $domain = new Domain();
            $domain->setName($name);
            $entityManager->persist($domain);
            $entityManager->flush();
        }

        return $domain;
    }

    private function alias(string $name, string $domain, string $destination): void
    {
        $alias = new Alias();
        $alias->setName($name);
        $alias->setDomain($this->domain($domain));
        $alias->setDestination($destination);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($alias);
        $entityManager->flush();
    }

    private function removeAlias(string $name, string $domain): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        foreach ($entityManager->getRepository(Alias::class)->findBy(['name' => $name, 'domain' => $this->domain($domain)]) as $alias) {
            $entityManager->remove($alias);
        }

        $entityManager->flush();
    }

    private function createUser(string $name): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setName($name);
        $user->setDomain($this->domain('example.com'));
        $user->setPassword('irrelevant');
        $user->setEnabled(true);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
