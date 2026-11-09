<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\User;
use App\Mcp\ToolDeniedException;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\FakeImapServer;
use Tests\Integration\Helper\FakeSmtpServer;
use Tests\Integration\Helper\McpClientTrait;

/**
 * message_draft over HTTP, down to what goes over the wire to a scripted IMAP server. The scripted
 * submission server proves that nothing is ever sent.
 */
class MailDraftToolsTest extends WebTestCase
{
    use McpClientTrait;

    private const array LIMIT_VARIABLES = [
        'MCP_MAIL_DRAFT_TOKEN_LIMIT_PER_HOUR',
        'MCP_MAIL_SEND_MAX_RECIPIENTS',
        'MCP_MAIL_SEND_MAX_BYTES',
    ];

    private const array SAVED_IN_DRAFTS = [
        'TAG1 OK Logged in',
        '* LIST (\HasNoChildren \Drafts) "." Drafts',
        'TAG2 OK List completed',
        '+ Ready for literal',
        'TAG3 OK [APPENDUID 1 9] Append completed',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();
        static::getContainer()->get('app.mail_send_limit_pool')->clear();
    }

    #[\Override]
    protected function tearDown(): void
    {
        FakeSmtpServer::$current = null;
        FakeImapServer::$current = null;
        $this->unsetEnvironment('MCP_ENABLED');

        foreach (self::LIMIT_VARIABLES as $variable) {
            $this->unsetEnvironment($variable);
        }

        parent::tearDown();
    }

    public function testADraftIsSavedFlaggedAsDraftAndNothingIsSent(): void
    {
        $smtp = $this->servers();

        $result = $this->draft($this->findUser(), [
            'to' => ['bob@example.com'],
            'cc' => ['carol@example.com'],
            'bcc' => ['hidden@example.com'],
            'subject' => 'Grüße',
            'body' => "Hallo Bob,\nschöne Grüße.",
        ]);

        self::assertTrue($result['drafted']);
        self::assertSame('Drafts', $result['mailbox']);
        self::assertSame(['bob@example.com', 'carol@example.com', 'hidden@example.com'], $result['recipients']);
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.com>$/', $result['message_id']);

        $imap = FakeImapServer::$current?->sent() ?? '';
        self::assertMatchesRegularExpression('/APPEND "Drafts" \(\\\\Draft \\\\Seen\) \{\d+\}/', $imap);
        self::assertStringContainsString('Message-ID: ' . $result['message_id'] . "\r\n", $imap);
        self::assertStringContainsString("\r\nDate: ", $imap);
        self::assertStringContainsString("Bcc: hidden@example.com\r\n", $imap);
        self::assertStringContainsString("To: bob@example.com\r\n", $imap);
        self::assertSame(0, $smtp->connections);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testTheSenderIsTheOwnerWhateverTheArgumentsSay(): void
    {
        $this->servers();

        $result = $this->draft($this->findUser(), ['from' => 'victim@example.com', 'sender' => 'victim@example.com', ...$this->mail()]);

        self::assertSame('admin@example.com', $result['from']);
        self::assertStringContainsString("From: admin@example.com\r\n", FakeImapServer::$current?->sent() ?? '');
        self::assertStringNotContainsString('victim', FakeImapServer::$current?->sent() ?? '');
    }

    public function testNoParameterNamesASenderOrAMailbox(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailDraft]);
        $tool = $this->toolDefinition($secret, $this->initialiseSession($secret), 'message_draft');

        self::assertSame(['to', 'subject', 'body', 'cc', 'bcc'], array_keys($tool['inputSchema']['properties'] ?? []));
        self::assertFalse($tool['annotations']['readOnlyHint'] ?? null);
        self::assertFalse($tool['annotations']['destructiveHint'] ?? null);
        self::assertFalse($tool['annotations']['idempotentHint'] ?? null);
        self::assertFalse($tool['annotations']['openWorldHint'] ?? null);
    }

    public function testASendTokenCannotDraftAndADraftTokenCannotSend(): void
    {
        $smtp = $this->servers();
        $user = $this->findUser();

        $sendOnly = $this->storeToken($user, [ApiTokenScope::MailSend, ApiTokenScope::MailRead, ApiTokenScope::ManageRead]);
        $sendSession = $this->initialiseSession($sendOnly);
        self::assertNotContains('message_draft', $this->listTools($sendOnly, $sendSession));
        $result = $this->callTool($sendOnly, $sendSession, 'message_draft', $this->mail());
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);

        $draftOnly = $this->storeToken($this->findUser(), [ApiTokenScope::MailDraft]);
        $draftSession = $this->initialiseSession($draftOnly);
        self::assertContains('message_draft', $this->listTools($draftOnly, $draftSession));
        self::assertNotContains('message_send', $this->listTools($draftOnly, $draftSession));
        $result = $this->callTool($draftOnly, $draftSession, 'message_send', $this->mail());
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);

        self::assertSame(0, $smtp->connections);
        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    public function testADisabledOwnerIsRefusedAtTheFirewall(): void
    {
        $this->servers();
        $user = $this->findUser();
        $secret = $this->storeToken($user, [ApiTokenScope::MailDraft]);
        $session = $this->initialiseSession($secret);

        $user->setEnabled(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $response = $this->mcpRequest('tools/call', ['name' => 'message_draft', 'arguments' => $this->mail()], 'Bearer ' . $secret, $session);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    public function testAUserWithoutMailboxAccessIsRefused(): void
    {
        $user = $this->findUser();
        $user->setSendOnly(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->servers();

        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailDraft]);
        $result = $this->callTool($secret, $this->initialiseSession($secret), 'message_draft', $this->mail());

        self::assertTrue($result['isError'] ?? false);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function draftsMailboxes(): iterable
    {
        yield 'Drafts wins over another marked one' => [
            ['* LIST (\HasNoChildren \Drafts) "." Entwürfe', '* LIST (\HasNoChildren \Drafts) "." Drafts'],
            'Drafts',
        ];
        yield 'the first by name when Drafts is not marked' => [
            ['* LIST (\HasNoChildren \Drafts) "." Zzz', '* LIST (\HasNoChildren \Drafts) "." Notizen'],
            'Notizen',
        ];
        yield 'Drafts when none is marked' => [['* LIST (\HasNoChildren) "." INBOX'], 'Drafts'];
    }

    /**
     * @param list<string> $listed
     */
    #[DataProvider('draftsMailboxes')]
    public function testTheDraftsMailboxIsPicked(array $listed, string $expected): void
    {
        $this->servers(['TAG1 OK Logged in', ...$listed, 'TAG2 OK List completed', '+ Ready for literal', 'TAG3 OK [APPENDUID 1 9] Append completed']);

        $result = $this->draft($this->findUser(), $this->mail());

        self::assertSame($expected, $result['mailbox']);
        self::assertStringContainsString(\sprintf('APPEND "%s" (\Draft \Seen)', $expected), FakeImapServer::$current?->sent() ?? '');
    }

    public function testAMissingDraftsMailboxIsCreated(): void
    {
        $this->servers([
            'TAG1 OK Logged in',
            '* LIST (\HasNoChildren) "." INBOX',
            'TAG2 OK List completed',
            '+ Ready for literal',
            'TAG3 NO [TRYCREATE] Mailbox does not exist: Drafts',
            'TAG4 OK Create completed',
            '+ Ready for literal',
            'TAG5 OK [APPENDUID 1 1] Append completed',
        ]);

        $result = $this->draft($this->findUser(), $this->mail());

        self::assertSame('Drafts', $result['mailbox']);
        self::assertStringContainsString('CREATE "Drafts"', FakeImapServer::$current?->sent() ?? '');
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function failures(): iterable
    {
        yield 'over quota' => [['TAG1 OK Logged in', '* LIST (\HasNoChildren \Drafts) "." Drafts', 'TAG2 OK List completed', '+ Ready for literal', 'TAG3 NO [OVERQUOTA] Quota exceeded'], false];
        yield 'too many sessions' => [['TAG1 NO [LIMIT] Maximum number of connections from user+IP exceeded'], true];
        yield 'connection lost' => [[], true];
    }

    /**
     * @param list<string> $script
     */
    #[DataProvider('failures')]
    public function testAFailedSaveIsAnErrorAndNeverASuccess(array $script, bool $temporary): void
    {
        $this->servers($script);

        $result = $this->draftExpectingError($this->findUser(), $this->mail());

        self::assertStringContainsString('draft was not saved', $result);
        self::assertSame($temporary, str_contains($result, 'Retry in a few seconds'));
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testAnyOtherFailureIsAnErrorToo(): void
    {
        $this->servers();
        self::assertInstanceOf(FakeImapServer::class, FakeImapServer::$current);
        FakeImapServer::$current->onLogin = static function (): void {
            throw new \RuntimeException('Something unexpected sentinel');
        };

        $result = $this->draftExpectingError($this->findUser(), $this->mail());

        self::assertStringContainsString('draft was not saved', $result);
        self::assertStringNotContainsString('sentinel', $result);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidMails(): iterable
    {
        yield 'no recipient' => [['to' => [], 'subject' => 's', 'body' => 'b']];
        yield 'an invalid recipient' => [['to' => ['not an address'], 'subject' => 's', 'body' => 'b']];
        yield 'a non-ASCII local part' => [['to' => ['jörg@example.com'], 'subject' => 's', 'body' => 'b']];
        yield 'an empty subject' => [['to' => ['bob@example.com'], 'subject' => ' ', 'body' => 'b']];
        yield 'a subject smuggling a header' => [['to' => ['bob@example.com'], 'subject' => "s\r\nBcc: eve@example.com", 'body' => 'b']];
        yield 'an empty body' => [['to' => ['bob@example.com'], 'subject' => 's', 'body' => '']];
    }

    /**
     * @param array<string, mixed> $mail
     */
    #[DataProvider('invalidMails')]
    public function testAnInvalidMailIsRefusedBeforeConnecting(array $mail): void
    {
        $this->servers();

        $this->draftExpectingError($this->findUser(), $mail);

        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    public function testTooManyRecipientsAreRefused(): void
    {
        $this->limit('MCP_MAIL_SEND_MAX_RECIPIENTS', '2');
        $this->servers();

        $result = $this->draftExpectingError($this->findUser(), [...$this->mail(), 'to' => ['a@example.com'], 'cc' => ['b@example.com'], 'bcc' => ['c@example.com']]);

        self::assertStringContainsString('at most 2 recipients', $result);
        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    public function testATooLargeMessageIsRefused(): void
    {
        $this->limit('MCP_MAIL_SEND_MAX_BYTES', '1024');
        $this->servers();

        $result = $this->draftExpectingError($this->findUser(), [...$this->mail(), 'body' => str_repeat('x', 2048)]);

        self::assertStringContainsString('1024 bytes', $result);
        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    public function testTheRateLimitPerTokenRefusesWithoutConnecting(): void
    {
        $this->limit('MCP_MAIL_DRAFT_TOKEN_LIMIT_PER_HOUR', '1');
        $this->servers();
        $user = $this->findUser();
        $secret = $this->storeToken($user, [ApiTokenScope::MailDraft]);
        $session = $this->initialiseSession($secret);

        $first = $this->callTool($secret, $session, 'message_draft', $this->mail());
        self::assertFalse($first['isError'] ?? false, (string) json_encode($first));

        $second = $this->callTool($secret, $session, 'message_draft', $this->mail());
        self::assertTrue($second['isError'] ?? false);
        self::assertMatchesRegularExpression('/not saved.*Retry in \d+ seconds/', $second['content'][0]['text']);
        self::assertSame(1, FakeImapServer::$current?->connections);
        self::assertSame(0, $this->shortLivedCredentialCount());

        $this->servers();
        self::assertTrue($this->draft($user, $this->mail())['drafted'], 'Another token has a limit of its own.');
    }

    public function testTheDefaultLimitIsSixtyDraftsPerTokenPerHour(): void
    {
        $this->servers();
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailDraft]);
        $session = $this->initialiseSession($secret);

        for ($i = 1; $i <= 60; ++$i) {
            FakeImapServer::$current = new FakeImapServer(self::SAVED_IN_DRAFTS);
            $result = $this->callTool($secret, $session, 'message_draft', $this->mail());
            self::assertFalse($result['isError'] ?? false, \sprintf('Draft %d: %s', $i, json_encode($result)));
        }

        FakeImapServer::$current = new FakeImapServer(self::SAVED_IN_DRAFTS);
        $refused = $this->callTool($secret, $session, 'message_draft', $this->mail());

        self::assertTrue($refused['isError'] ?? false);
        self::assertMatchesRegularExpression('/not saved.*Retry in \d+ seconds/', $refused['content'][0]['text']);
        self::assertSame(0, FakeImapServer::$current->connections);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testAuditLogHasRecipientsMessageIdAndFolderButNotTheContent(): void
    {
        $this->servers();

        $result = $this->draft($this->findUser(), [
            'to' => ['bob@example.com'],
            'bcc' => ['hidden@example.com'],
            'subject' => 'Private subject',
            'body' => 'the secret plan',
        ]);

        $record = $this->auditRecords()[0];
        self::assertSame('ok', $record->context['result']);
        self::assertSame(['bob@example.com', 'hidden@example.com'], $record->context['recipients']);
        self::assertSame($result['message_id'], $record->context['message_id']);
        self::assertSame('Drafts', $record->context['draft_folder']);
        self::assertStringNotContainsString('secret plan', (string) json_encode($record->toArray()));
        self::assertStringNotContainsString('Private subject', (string) json_encode($record->toArray()));
    }

    public function testAuditLogRecordsAFailedDraftWithItsRecipients(): void
    {
        $this->servers([]);

        $this->draftExpectingError($this->findUser(), $this->mail());

        $record = $this->auditRecords()[0];
        self::assertSame('failed', $record->context['result']);
        self::assertSame(['bob@example.com'], $record->context['recipients']);
        self::assertArrayHasKey('message_id', $record->context);
    }

    /**
     * @return list<LogRecord>
     */
    private function auditRecords(): array
    {
        $handler = static::getContainer()->get('app.test.mcp_audit_records');
        $records = array_values(array_filter($handler->getRecords(), static fn (LogRecord $record): bool => 'message_draft' === ($record->context['tool'] ?? null)));
        self::assertCount(1, $records);

        return $records;
    }

    /**
     * @param list<string> $imap
     */
    private function servers(array $imap = self::SAVED_IN_DRAFTS): FakeSmtpServer
    {
        FakeImapServer::$current = new FakeImapServer($imap);

        return FakeSmtpServer::$current = new FakeSmtpServer([]);
    }

    /**
     * @return array{to: list<string>, subject: string, body: string}
     */
    private function mail(): array
    {
        return ['to' => ['bob@example.com'], 'subject' => 'Hello', 'body' => 'Hello Bob'];
    }

    private function limit(string $variable, string $value): void
    {
        $this->setEnvironment($variable, $value);
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{drafted: bool, message_id: string, from: string, recipients: list<string>, mailbox: string}
     */
    private function draft(User $user, array $arguments): array
    {
        // Whatever the test holds may belong to an entity manager from before a kernel reboot.
        $secret = $this->storeToken($this->findUser((string) $user), [ApiTokenScope::MailDraft]);
        $output = $this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'message_draft', $arguments));

        /** @var array{drafted: bool, message_id: string, from: string, recipients: list<string>, mailbox: string} $typed */
        $typed = $output;

        return $typed;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function draftExpectingError(User $user, array $arguments): string
    {
        $secret = $this->storeToken($this->findUser((string) $user), [ApiTokenScope::MailDraft]);
        $result = $this->callTool($secret, $this->initialiseSession($secret), 'message_draft', $arguments);

        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));

        return $result['content'][0]['text'];
    }
}
