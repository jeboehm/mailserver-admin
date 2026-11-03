<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\Domain;
use App\Entity\User;
use App\Mcp\ToolDeniedException;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\FakeImapServer;
use Tests\Integration\Helper\MailToolOutputTrait;
use Tests\Integration\Helper\McpClientTrait;

/**
 * The mail read tools over HTTP, down to the commands on the wire, against a scripted IMAP server.
 * The real server is in MailReadEndToEndTest.
 */
class MailReadToolsTest extends WebTestCase
{
    use MailToolOutputTrait;
    use McpClientTrait;

    private const string RETRYABLE = 'The mailbox is busy or temporarily unavailable. Retry in a few seconds.';
    private const string TEXT_MESSAGE_HEADERS = "From: =?UTF-8?Q?J=C3=B6rg?= <j@example.test>\r\nSubject: =?UTF-8?B?R3LDvMOfZQ==?=\r\nMessage-ID: <t1@example.test>\r\n\r\n";

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->setEnvironment('MCP_MAIL_READ_ENABLED', 'true');
        $this->client = static::createClient();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FakeImapServer::$current = null;
        $this->unsetEnvironment('MCP_ENABLED');
        $this->unsetEnvironment('MCP_MAIL_READ_ENABLED');
        $this->unsetEnvironment('MCP_MAIL_MESSAGE_MAX_BYTES');

        parent::tearDown();
    }

    public function testToolsAreUnavailableWhileTheFeatureIsOff(): void
    {
        $this->unsetEnvironment('MCP_MAIL_READ_ENABLED');
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $server = $this->server([]);
        $secret = $this->storeToken($this->findUser());
        $session = $this->initialiseSession($secret);

        self::assertNotContains('mailbox_list', $this->listTools($secret, $session));

        $result = $this->callTool($secret, $session, 'mailbox_list');

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertSame(0, $server->connections);
    }

    public function testToolsNeedTheMailReadScope(): void
    {
        $server = $this->server([]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageRead, ApiTokenScope::ManageWrite, ApiTokenScope::MailSend]);
        $session = $this->initialiseSession($secret);

        $tools = $this->listTools($secret, $session);
        self::assertNotContains('mailbox_list', $tools);
        self::assertNotContains('message_search', $tools);
        self::assertNotContains('message_get', $tools);

        $result = $this->callTool($secret, $session, 'message_get', ['mailbox' => 'INBOX', 'uid' => 1]);

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertSame(0, $server->connections);
    }

    public function testNoToolHasAParameterThatNamesAMailboxUser(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);
        $parameters = [];

        foreach ($this->toolCatalogue($secret, $this->initialiseSession($secret)) as $tool) {
            $parameters[$tool['name']] = array_keys($tool['inputSchema']['properties'] ?? []);
        }

        self::assertSame(['limit', 'offset'], $parameters['mailbox_list']);
        self::assertSame(['mailbox', 'uid'], $parameters['message_get']);
        self::assertSame(
            ['mailbox', 'text', 'from', 'to', 'subject', 'since', 'before', 'unseen_only', 'limit', 'offset'],
            $parameters['message_search'],
        );
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function roles(): iterable
    {
        yield 'admin' => [true, false];
        yield 'domain admin' => [false, true];
        yield 'user' => [false, false];
    }

    #[DataProvider('roles')]
    public function testEveryRoleLogsInAsItselfWhateverTheArgumentsSay(bool $admin, bool $domainAdmin): void
    {
        $victim = $this->createUser('victim');
        $actor = $this->createUser('actor');
        $actor->setAdmin($admin);
        $actor->setDomainAdmin($domainAdmin);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $server = $this->server(['TAG1 OK Logged in', 'TAG2 NO [NONEXISTENT] Mailbox does not exist']);
        $secret = $this->storeToken($actor, [ApiTokenScope::MailRead]);
        $session = $this->initialiseSession($secret);

        $result = $this->callTool($secret, $session, 'message_get', [
            'mailbox' => 'victim@example.com/INBOX',
            'uid' => 1,
            'user' => (string) $victim,
            'mailbox_user' => (string) $victim,
            'login' => 'cred#victim@example.com',
        ]);

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertStringContainsString('LOGIN "cred#actor@example.com"', $server->sent());
        self::assertStringNotContainsString('LOGIN "cred#victim', $server->sent());
    }

    #[DataProvider('roles')]
    public function testEveryRoleIsIssuedACredentialForItselfOnly(bool $admin, bool $domainAdmin): void
    {
        $this->createUser('victim');
        $actor = $this->createUser('actor');
        $actor->setAdmin($admin);
        $actor->setDomainAdmin($domainAdmin);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $rows = [];
        $server = $this->server(['TAG1 OK Logged in', 'TAG2 OK Listed']);
        $connection = $this->connection();
        $server->onLogin = static function () use (&$rows, $connection): void {
            $rows = $connection->fetchAllAssociative('SELECT user_id, kind, allow_imap, allow_smtp FROM mail_mailbox_credentials');
        };

        $secret = $this->storeToken($actor, [ApiTokenScope::MailRead]);
        $this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list');

        self::assertCount(1, $rows);
        self::assertIsNumeric($rows[0]['user_id']);
        self::assertSame($actor->getId(), (int) $rows[0]['user_id']);
        self::assertSame('short_lived', $rows[0]['kind']);
        self::assertTrue((bool) $rows[0]['allow_imap']);
        self::assertFalse((bool) $rows[0]['allow_smtp']);
    }

    public function testNoCredentialRemainsAfterASuccessfulCall(): void
    {
        $this->server(['TAG1 OK Logged in', 'TAG2 OK Listed']);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        self::assertFalse($this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list')['isError'] ?? false);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testNoCredentialRemainsAfterAFailedLogin(): void
    {
        $this->server(['TAG1 NO [AUTHENTICATIONFAILED] Authentication failed.']);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        self::assertTrue($this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list')['isError'] ?? false);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testConnectionLimitOfTheServerIsReportedAsRetryable(): void
    {
        $this->server(['TAG1 NO [LIMIT] Maximum number of connections from user+IP exceeded']);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list');

        self::assertTrue($result['isError'] ?? false);
        self::assertSame(self::RETRYABLE, $result['content'][0]['text']);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testATemporaryAuthFailureIsReportedAsRetryable(): void
    {
        $this->server(['TAG1 NO [UNAVAILABLE] Temporary authentication failure.']);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        self::assertSame(self::RETRYABLE, $this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list')['content'][0]['text']);
    }

    public function testAFailedLoginDoesNotPassTheAnswerOfTheServerOn(): void
    {
        $this->server(['TAG1 NO [AUTHENTICATIONFAILED] Authentication failed for cred#admin@example.com']);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        self::assertSame('The mailbox could not be read.', $this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list')['content'][0]['text']);
    }

    public function testTheParallelLimitRefusesBeforeConnecting(): void
    {
        $user = $this->findUser();
        $connection = $this->connection();

        for ($i = 0; $i < 5; ++$i) {
            $connection->executeStatement(
                "INSERT INTO mail_mailbox_credentials (user_id, secret_hash, kind, allow_imap, allow_smtp, expires_at, created_at) VALUES (?, ?, 'short_lived', ?, ?, CURRENT_TIMESTAMP + INTERVAL '60' SECOND, CURRENT_TIMESTAMP)",
                [$user->getId(), bin2hex(random_bytes(32)), true, false],
                [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::STRING, \Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN],
            );
        }

        $server = $this->server([]);
        $secret = $this->storeToken($user, [ApiTokenScope::MailRead]);

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list');

        self::assertSame(self::RETRYABLE, $result['content'][0]['text']);
        self::assertSame(0, $server->connections);
    }

    public function testSendOnlyUsersAreRefusedLikeAnythingElseMissing(): void
    {
        $user = $this->createUser('postbox');
        $user->setSendOnly(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $server = $this->server([]);
        $secret = $this->storeToken($user, [ApiTokenScope::MailRead]);

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list');

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertSame(0, $server->connections);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testMailboxListShowsEveryMailboxWithItsCounters(): void
    {
        $server = $this->server([
            'TAG1 OK Logged in',
            '* LIST (\HasNoChildren \Sent) "/" Sent',
            '* LIST (\HasNoChildren) "/" Pr&APw-fung',
            '* LIST (\HasNoChildren) "/" INBOX',
            '* LIST (\Noselect \HasChildren) "/" Archive',
            'TAG2 OK Listed',
            '* STATUS INBOX (MESSAGES 3 UNSEEN 1)',
            'TAG3 OK Status',
            '* STATUS "Pr&APw-fung" (MESSAGES 2 UNSEEN 0)',
            'TAG4 OK Status',
            '* STATUS Sent (MESSAGES 1 UNSEEN 0)',
            'TAG5 OK Status',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->asMailboxList($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list')));

        self::assertSame(4, $result['total']);
        self::assertSame(['INBOX', 'Archive', 'Prüfung', 'Sent'], array_column($result['mailboxes'], 'name'));
        self::assertSame(3, $result['mailboxes'][0]['messages']);
        self::assertSame(1, $result['mailboxes'][0]['unseen']);
        self::assertNull($result['mailboxes'][1]['messages']);
        self::assertSame(2, $result['mailboxes'][2]['messages']);
        self::assertContains('\Sent', $result['mailboxes'][3]['attributes']);
        self::assertStringNotContainsString('SELECT', $server->sent());
    }

    public function testMailboxNamesAreOnlyEverNames(): void
    {
        $this->server([
            'TAG1 OK Logged in',
            '* LIST (\Noselect) "/" 2024',
            '* LIST (\Noselect) "/" "=?UTF-8?B?QQ==?="',
            'TAG2 OK Listed',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->asMailboxList($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list')));

        self::assertSame(['2024', '=?UTF-8?B?QQ==?='], array_column($result['mailboxes'], 'name'));
    }

    public function testMailboxListPaginates(): void
    {
        $this->server([
            'TAG1 OK Logged in',
            '* LIST () "/" Sent',
            '* LIST () "/" INBOX',
            '* LIST () "/" Junk',
            'TAG2 OK Listed',
            '* STATUS Junk (MESSAGES 7 UNSEEN 7)',
            'TAG3 OK Status',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->asMailboxList($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'mailbox_list', ['limit' => 1, 'offset' => 1])));

        self::assertSame(3, $result['total']);
        self::assertSame(['Junk'], array_column($result['mailboxes'], 'name'));
    }

    public function testMessageSearchOpensReadOnlyAndReturnsAPageOfMarkedHeaders(): void
    {
        $server = $this->server([
            'TAG1 OK Logged in',
            '* 3 EXISTS',
            'TAG2 OK [READ-ONLY] Examined',
            '+ Ready for literal',
            '* SEARCH 2 7 9',
            'TAG3 OK Searched',
            ...$this->envelopeResponse(1, 7, self::TEXT_MESSAGE_HEADERS),
            ...$this->envelopeResponse(2, 2, "Subject: Older\r\n\r\n"),
            'TAG4 OK Fetched',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->asMessageSearch($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'message_search', [
            'mailbox' => 'INBOX',
            'subject' => 'Grüße',
            'since' => '2026-01-05',
            'limit' => 2,
            'offset' => 1,
        ])));

        self::assertSame(3, $result['total']);
        self::assertStringContainsString('untrusted', $result['notice']);
        self::assertSame([7, 2], array_column($result['messages'], 'uid'));
        self::assertSame('2026-11-03T14:03:09+00:00', $result['messages'][0]['received_at']);
        self::assertSame(['\Recent'], $result['messages'][0]['flags']);
        self::assertSame('Jörg <j@example.test>', $result['messages'][0]['untrusted_external_content']['from']);
        self::assertSame('Grüße', $result['messages'][0]['untrusted_external_content']['subject']);

        $sent = $server->sent();
        self::assertStringContainsString('EXAMINE "INBOX"', $sent);
        self::assertStringNotContainsString('SELECT', $sent);
        self::assertStringContainsString('UID SEARCH CHARSET UTF-8 SUBJECT {7}', $sent, 'Search values go as literals.');
        self::assertStringContainsString("\nGrüße SINCE 5-Jan-2026", $sent);
        self::assertStringContainsString('UID FETCH 7,2 ', $sent);
        self::assertStringContainsString('BODY.PEEK[HEADER.FIELDS', $sent);
        self::assertStringNotContainsString(' BODY[', $sent);
    }

    public function testMessageSearchCannotBeInjectedWithSearchKeys(): void
    {
        $server = $this->server([
            'TAG1 OK Logged in',
            'TAG2 OK Examined',
            '+ Ready for literal',
            '* SEARCH',
            'TAG3 OK Searched',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);
        $value = "x\" OR ALL \"\r\nA1 DELETE INBOX";

        $result = $this->asMessageSearch($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'message_search', ['text' => $value])));

        self::assertSame(0, $result['total']);
        self::assertSame(\sprintf('TAG3 UID SEARCH CHARSET UTF-8 TEXT {%d}', \strlen($value)), $server->commands[2]);
        self::assertSame($value, $server->commands[3]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidSearches(): iterable
    {
        yield 'limit too large' => [['limit' => 101]];
        yield 'limit too small' => [['limit' => 0]];
        yield 'negative offset' => [['offset' => -1]];
        yield 'not a date' => [['since' => 'yesterday']];
        yield 'impossible date' => [['before' => '2026-02-31']];
        yield 'empty value' => [['from' => ' ']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidSearches')]
    public function testInvalidSearchesAreRefusedBeforeConnecting(array $arguments): void
    {
        $server = $this->server([]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'message_search', $arguments);

        self::assertTrue($result['isError'] ?? false);
        self::assertSame(0, $server->connections);
    }

    public function testMessageSearchAuditsTheMailboxItDefaultsTo(): void
    {
        $this->server([
            'TAG1 OK Logged in',
            '* 0 EXISTS',
            'TAG2 OK Examined',
            '* SEARCH',
            'TAG3 OK Searched',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $this->callTool($secret, $this->initialiseSession($secret), 'message_search');

        $handler = static::getContainer()->get('app.test.mcp_audit_records');
        $records = array_values(array_filter($handler->getRecords(), static fn (LogRecord $record): bool => 'message_search' === ($record->context['tool'] ?? null)));

        self::assertCount(1, $records);
        self::assertSame('mailbox:INBOX', $records[0]->context['target']);
    }

    public function testMessageGetReturnsHeadersTextAndAttachmentMetadataWithPeek(): void
    {
        $body = "Gr=FC=DFe\r\n";
        $server = $this->server([
            'TAG1 OK Logged in',
            'TAG2 OK Examined',
            ...$this->envelopeResponse(1, 7, self::TEXT_MESSAGE_HEADERS, ' BODYSTRUCTURE (("text" "plain" ("charset" "iso-8859-1") NIL NIL "quoted-printable" 11 1 NIL NIL NIL NIL) ("application" "pdf" ("name" "R.pdf") NIL NIL "base64" 2048 NIL ("attachment" ("filename" "R.pdf")) NIL NIL) "mixed" ("boundary" "b") NIL NIL NIL)'),
            'TAG3 OK Fetched',
            \sprintf('* 1 FETCH (UID 7 BODY[1]<0> {%d}', \strlen($body)),
            $body . ')',
            'TAG4 OK Fetched',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->asMessage($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'message_get', ['mailbox' => 'INBOX', 'uid' => 7])));

        self::assertStringContainsString('untrusted', $result['notice']);
        self::assertSame(7, $result['uid']);
        self::assertFalse($result['body_truncated']);
        self::assertFalse($result['body_converted_from_html']);
        self::assertSame("Grüße\r\n", $result['untrusted_external_content']['body']);
        self::assertSame('<t1@example.test>', $result['untrusted_external_content']['message_id']);
        self::assertSame(
            [['filename' => 'R.pdf', 'content_type' => 'application/pdf', 'size' => 2048]],
            $result['untrusted_external_content']['attachments'],
        );

        $sent = $server->sent();
        self::assertStringContainsString('EXAMINE "INBOX"', $sent);
        self::assertStringNotContainsString('SELECT', $sent);
        self::assertStringContainsString('BODY.PEEK[1]<0.196608>', $sent, 'The size limit is applied by the fetch.');
        self::assertStringNotContainsString(' BODY[', $sent);
    }

    public function testMessageGetConvertsAnHtmlOnlyMessage(): void
    {
        $body = '<p>Hello <b>there</b></p>';
        $this->server([
            'TAG1 OK Logged in',
            'TAG2 OK Examined',
            ...$this->envelopeResponse(1, 3, "Subject: Html\r\n\r\n", ' BODYSTRUCTURE ("text" "html" ("charset" "utf-8") NIL NIL "8bit" 25 1 NIL NIL NIL NIL)'),
            'TAG3 OK Fetched',
            \sprintf('* 1 FETCH (UID 3 BODY[1]<0> {%d}', \strlen($body)),
            $body . ')',
            'TAG4 OK Fetched',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->asMessage($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'message_get', ['mailbox' => 'INBOX', 'uid' => 3])));

        self::assertSame('Hello there', $result['untrusted_external_content']['body']);
        self::assertTrue($result['body_converted_from_html']);
    }

    public function testMessageGetCutsABodyOverTheLimit(): void
    {
        $this->setEnvironment('MCP_MAIL_MESSAGE_MAX_BYTES', '10');
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $body = '0123456789';
        $server = $this->server([
            'TAG1 OK Logged in',
            'TAG2 OK Examined',
            ...$this->envelopeResponse(1, 3, "Subject: Big\r\n\r\n", ' BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "8bit" 1048576 1 NIL NIL NIL NIL)'),
            'TAG3 OK Fetched',
            \sprintf('* 1 FETCH (UID 3 BODY[1]<0> {%d}', \strlen($body)),
            $body . ')',
            'TAG4 OK Fetched',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->asMessage($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'message_get', ['mailbox' => 'INBOX', 'uid' => 3])));

        self::assertTrue($result['body_truncated']);
        self::assertSame($body, $result['untrusted_external_content']['body']);
        self::assertStringContainsString('BODY.PEEK[1]<0.10>', $server->sent());
    }

    public function testMessageGetOfAMissingMessageLooksLikeAnyDenial(): void
    {
        $this->server(['TAG1 OK Logged in', 'TAG2 OK Examined', 'TAG3 OK Fetched']);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'message_get', ['mailbox' => 'INBOX', 'uid' => 99]);

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testAuditLogNamesTheTargetAndNotTheContent(): void
    {
        $body = 'the secret plan';
        $this->server([
            'TAG1 OK Logged in',
            'TAG2 OK Examined',
            ...$this->envelopeResponse(1, 7, "Subject: Private subject\r\n\r\n", ' BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "8bit" 15 1 NIL NIL NIL NIL)'),
            'TAG3 OK Fetched',
            \sprintf('* 1 FETCH (UID 7 BODY[1]<0> {%d}', \strlen($body)),
            $body . ')',
            'TAG4 OK Fetched',
        ]);
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead]);

        $this->callTool($secret, $this->initialiseSession($secret), 'message_get', ['mailbox' => 'INBOX', 'uid' => 7]);

        $handler = static::getContainer()->get('app.test.mcp_audit_records');
        $records = array_filter($handler->getRecords(), static fn (LogRecord $record): bool => 'message_get' === ($record->context['tool'] ?? null));
        self::assertCount(1, $records);

        $record = array_values($records)[0];
        self::assertSame('message:INBOX/7', $record->context['target']);
        self::assertSame('ok', $record->context['result']);
        self::assertStringNotContainsString('secret plan', (string) json_encode($record->toArray()));
        self::assertStringNotContainsString('Private subject', (string) json_encode($record->toArray()));
    }

    /**
     * @param list<string> $script
     */
    private function server(array $script): FakeImapServer
    {
        return FakeImapServer::$current = new FakeImapServer($script);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function createUser(string $name): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $domain = $entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        self::assertInstanceOf(Domain::class, $domain);

        $user = new User();
        $user->setName($name);
        $user->setDomain($domain);
        $user->setPassword('irrelevant');
        $user->setEnabled(true);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /**
     * @return list<string>
     */
    private function envelopeResponse(int $sequence, int $uid, string $headers, string $extra = ''): array
    {
        return [
            \sprintf('* %d FETCH (UID %d FLAGS (\Recent) RFC822.SIZE 520 INTERNALDATE "03-Nov-2026 14:03:09 +0000"%s BODY[HEADER.FIELDS (FROM TO CC REPLY-TO SUBJECT DATE MESSAGE-ID)]<0> {%d}', $sequence, $uid, $extra, \strlen($headers)),
            $headers . ')',
        ];
    }
}
