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
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\FakeImapServer;
use Tests\Integration\Helper\FakeSmtpServer;
use Tests\Integration\Helper\MailToolOutputTrait;
use Tests\Integration\Helper\McpClientTrait;

/**
 * message_send over HTTP, down to what goes over the wire, against a scripted submission server and
 * a scripted IMAP server. The real servers are in MailSendEndToEndTest.
 */
class MailSendToolsTest extends WebTestCase
{
    use MailToolOutputTrait;
    use McpClientTrait;

    private const array LIMIT_VARIABLES = [
        'MCP_MAIL_SEND_TOKEN_LIMIT_PER_HOUR',
        'MCP_MAIL_SEND_USER_LIMIT_PER_HOUR',
        'MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE',
        'MCP_MAIL_SEND_MAX_RECIPIENTS',
        'MCP_MAIL_SEND_MAX_BYTES',
    ];

    private const array SAVED_IN_SENT = [
        'TAG1 OK Logged in',
        '* LIST (\HasNoChildren \Sent) "." Sent',
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

    public function testToolIsUnavailableWhileTheFeatureIsOff(): void
    {
        $this->unsetEnvironment('MCP_MAIL_SEND_ENABLED');
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $smtp = $this->servers();
        $secret = $this->storeToken($this->findUser());
        $session = $this->initialiseSession($secret);

        self::assertNotContains('message_send', $this->listTools($secret, $session));

        $result = $this->callTool($secret, $session, 'message_send', $this->mail());

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertSame(0, $smtp->connections);
    }

    public function testToolNeedsTheMailSendScope(): void
    {
        $smtp = $this->servers();
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailRead, ApiTokenScope::ManageRead, ApiTokenScope::ManageWrite]);
        $session = $this->initialiseSession($secret);

        self::assertNotContains('message_send', $this->listTools($secret, $session));

        $result = $this->callTool($secret, $session, 'message_send', $this->mail());

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        self::assertSame(0, $smtp->connections);
    }

    public function testADisabledOwnerIsRefusedAtTheFirewall(): void
    {
        $smtp = $this->servers();
        $user = $this->createUser('disabled-sender');
        $secret = $this->storeToken($user, [ApiTokenScope::MailSend]);
        $session = $this->initialiseSession($secret);

        $user->setEnabled(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $response = $this->mcpRequest('tools/call', ['name' => 'message_send', 'arguments' => $this->mail()], 'Bearer ' . $secret, $session);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(0, $smtp->connections);
    }

    public function testTheOnlySenderParameterIsTheOptionalFromAddress(): void
    {
        $this->servers();
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailSend]);
        $tool = $this->toolDefinition($secret, $this->initialiseSession($secret), 'message_send');

        self::assertSame(['to', 'subject', 'body', 'cc', 'bcc', 'from_address'], array_keys($tool['inputSchema']['properties'] ?? []));
        self::assertSame('string', $tool['inputSchema']['properties']['to']['items']['type'] ?? null);
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
    public function testEveryRoleSendsAsItselfWhateverTheArgumentsSay(bool $admin, bool $domainAdmin): void
    {
        $actor = $this->createUser('actor');
        $actor->setAdmin($admin);
        $actor->setDomainAdmin($domainAdmin);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $smtp = $this->servers();
        $result = $this->send($actor, ['from' => 'victim@example.com', 'sender' => 'victim@example.com', ...$this->mail()]);

        self::assertSame(['cred#actor@example.com'], $smtp->logins);
        self::assertSame(['actor@example.com'], $smtp->mailFrom);
        self::assertSame('actor@example.com', $result['from']);
        self::assertStringContainsString("From: actor@example.com\r\n", $smtp->data);
        self::assertStringNotContainsString('victim', $smtp->data);
    }

    public function testASentMailGoesOverTheWireAndIsReported(): void
    {
        $smtp = $this->servers();

        $result = $this->send($this->findUser(), [
            'to' => ['bob@example.com'],
            'cc' => ['carol@example.com'],
            'subject' => 'Grüße',
            'body' => "Hallo Bob,\nschöne Grüße.",
        ]);

        self::assertTrue($result['sent']);
        self::assertSame(['bob@example.com', 'carol@example.com'], $smtp->rcptTo);
        self::assertTrue($smtp->quit);
        self::assertMatchesRegularExpression('/^<[0-9a-f]{32}@example\.com>$/', $result['message_id']);
        self::assertStringContainsString("To: bob@example.com\r\n", $smtp->data);
        self::assertStringContainsString("Cc: carol@example.com\r\n", $smtp->data);
        self::assertStringContainsString('Message-ID: ' . $result['message_id'] . "\r\n", $smtp->data);
        self::assertStringContainsString('Subject: =?utf-8?', $smtp->data);
        self::assertStringContainsString('sch=C3=B6ne Gr=C3=BC=C3=9Fe.', $smtp->data);
    }

    public function testTheMailAndItsCopyCarryTheSameFixedXMailerHeader(): void
    {
        $smtp = $this->servers();

        $this->send($this->findUser(), $this->mail());

        $header = "X-Mailer: mailserver-admin (https://github.com/jeboehm/mailserver-admin)\r\n";
        self::assertSame(1, substr_count($smtp->data, 'X-Mailer:'));
        self::assertStringContainsString($header, $smtp->data);
        self::assertStringContainsString($header, FakeImapServer::$current?->sent() ?? '');
    }

    public function testBlindCopiesAreDeliveredButOnlyVisibleInTheSentCopy(): void
    {
        $smtp = $this->servers();

        $result = $this->send($this->findUser(), [...$this->mail(), 'bcc' => ['hidden@example.com']]);

        self::assertSame(['bob@example.com', 'hidden@example.com'], $smtp->rcptTo);
        self::assertStringNotContainsString('hidden@example.com', $smtp->data);
        self::assertSame('saved', $result['sent_copy']['status']);
        self::assertStringContainsString("Bcc: hidden@example.com\r\n", FakeImapServer::$current?->sent() ?? '');
    }

    public function testTheCredentialsAreSeparateAndGoneAfterASend(): void
    {
        $smtp = $this->servers();
        $seen = [];
        $connection = $this->connection();
        $row = static fn (): array => $connection->fetchAllAssociative("SELECT allow_imap, allow_smtp FROM mail_mailbox_credentials WHERE kind = 'short_lived'");
        $smtp->onAuth = static function () use (&$seen, $row): void {
            $seen['smtp'] = $row();
        };
        self::assertInstanceOf(FakeImapServer::class, FakeImapServer::$current);
        FakeImapServer::$current->onLogin = static function () use (&$seen, $row): void {
            $seen['imap'] = $row();
        };

        $this->send($this->findUser(), $this->mail());

        self::assertCount(1, $seen['smtp']);
        self::assertTrue((bool) $seen['smtp'][0]['allow_smtp']);
        self::assertFalse((bool) $seen['smtp'][0]['allow_imap']);
        self::assertCount(1, $seen['imap']);
        self::assertTrue((bool) $seen['imap'][0]['allow_imap']);
        self::assertFalse((bool) $seen['imap'][0]['allow_smtp']);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function temporaryFailures(): iterable
    {
        yield '421 on connecting' => [['GREETING' => '421 4.7.0 mta Error: too many connections'], 'Retry in a few seconds.'];
        yield '454 on login' => [['AUTH' => '454 4.7.0 Temporary authentication failure: Connection lost to authentication server'], 'Retry in a few seconds.'];
        yield '421 on sending' => [['MAIL' => '421 4.3.2 Service not available'], 'Retry in a few seconds.'];
        yield '451 on the recipient' => [['RCPT' => '451 4.3.0 Temporary lookup failure'], 'Retry in a few seconds.'];
        yield 'connection refused' => [['GREETING' => ''], 'Retry in a few seconds.'];
    }

    /**
     * @param array<string, string> $replies
     */
    #[DataProvider('temporaryFailures')]
    public function testTemporaryFailuresAreRetryableAndLeaveNoCredential(array $replies, string $expected): void
    {
        $this->servers($replies);

        $result = $this->sendExpectingError($this->findUser(), $this->mail());

        self::assertStringContainsString('was not sent', $result);
        self::assertStringContainsString($expected, $result);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testARefusedLoginIsNotRetryable(): void
    {
        $this->servers(['AUTH' => '535 5.7.8 Error: authentication failed']);

        $result = $this->sendExpectingError($this->findUser(), $this->mail());

        self::assertStringContainsString('was not sent', $result);
        self::assertStringContainsString('535', $result);
        self::assertStringNotContainsString('Retry', $result);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testARefusedRecipientIsReportedWithItsCodeAndWithoutTheServerText(): void
    {
        $this->servers(['RCPT' => '550 5.1.1 secret internal detail']);

        $result = $this->sendExpectingError($this->findUser(), $this->mail());

        self::assertStringContainsString('was not sent', $result);
        self::assertStringContainsString('550', $result);
        self::assertStringNotContainsString('secret internal detail', $result);
        self::assertStringNotContainsString('Retry', $result);
    }

    /**
     * Retrying this would send the mail twice if the server had taken it.
     */
    public function testAConnectionLostWhileSendingLeavesTheOutcomeOpen(): void
    {
        $this->servers(['END_OF_DATA' => '']);

        $result = $this->sendExpectingError($this->findUser(), $this->mail());

        self::assertStringContainsString('may have been sent', $result);
        self::assertStringNotContainsString('Retry', $result);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testNoSentCopyIsAttemptedForAFailedSend(): void
    {
        $this->servers(['RCPT' => '550 5.1.1 No such user']);

        $this->sendExpectingError($this->findUser(), $this->mail());

        self::assertSame(0, FakeImapServer::$current?->connections);
    }

    public function testTheCopyIsAppendedAsSeenWithTheSentBytes(): void
    {
        $smtp = $this->servers();

        $result = $this->send($this->findUser(), $this->mail());

        self::assertSame(['status' => 'saved', 'mailbox' => 'Sent'], $result['sent_copy']);

        $sent = FakeImapServer::$current?->sent() ?? '';
        self::assertMatchesRegularExpression('/APPEND "Sent" \(\\\\Seen\) \{\d+\}/', $sent);
        self::assertStringContainsString(trim($smtp->data), $sent);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function sentMailboxes(): iterable
    {
        yield 'Sent wins over Sent Messages, in either order' => [
            ['* LIST (\HasNoChildren \Sent) "." "Sent Messages"', '* LIST (\HasNoChildren \Sent) "." Sent'],
            'Sent',
        ];
        yield 'Sent wins over one that sorts before it' => [
            ['* LIST (\HasNoChildren \Sent) "." Sent', '* LIST (\HasNoChildren \Sent) "." "Gesendet"'],
            'Sent',
        ];
        yield 'the only one marked' => [
            ['* LIST (\HasNoChildren) "." Sent', '* LIST (\HasNoChildren \Sent) "." "Gesendet"'],
            'Gesendet',
        ];
        yield 'the first of several by name' => [
            ['* LIST (\HasNoChildren \Sent) "." "Zzz"', '* LIST (\HasNoChildren \Sent) "." "Sent Messages"'],
            'Sent Messages',
        ];
        yield 'Sent when none is marked' => [
            ['* LIST (\HasNoChildren) "." INBOX'],
            'Sent',
        ];
    }

    /**
     * @param list<string> $listed
     */
    #[DataProvider('sentMailboxes')]
    public function testOneMailboxIsPicked(array $listed, string $expected): void
    {
        $this->servers(imap: ['TAG1 OK Logged in', ...$listed, 'TAG2 OK List completed', '+ Ready for literal', 'TAG3 OK [APPENDUID 1 9] Append completed']);

        $result = $this->send($this->findUser(), $this->mail());

        self::assertSame(['status' => 'saved', 'mailbox' => $expected], $result['sent_copy']);
        self::assertStringContainsString(\sprintf('APPEND "%s" (\Seen)', $expected), FakeImapServer::$current?->sent() ?? '');
    }

    public function testAMissingSentMailboxIsCreatedAfterTryCreate(): void
    {
        $this->servers(imap: [
            'TAG1 OK Logged in',
            '* LIST (\HasNoChildren) "." INBOX',
            'TAG2 OK List completed',
            '+ Ready for literal',
            'TAG3 NO [TRYCREATE] Mailbox does not exist: Sent',
            'TAG4 OK Create completed',
            '+ Ready for literal',
            'TAG5 OK [APPENDUID 1 1] Append completed',
        ]);

        $result = $this->send($this->findUser(), $this->mail());

        self::assertSame(['status' => 'saved', 'mailbox' => 'Sent'], $result['sent_copy']);
        self::assertStringContainsString('CREATE "Sent"', FakeImapServer::$current?->sent() ?? '');
        self::assertSame(2, substr_count(FakeImapServer::$current?->sent() ?? '', 'APPEND "Sent"'));
    }

    public function testAMailboxCreatedInTheMeantimeIsJustAsGood(): void
    {
        $this->servers(imap: [
            'TAG1 OK Logged in',
            'TAG2 OK List completed',
            '+ Ready for literal',
            'TAG3 NO [TRYCREATE] Mailbox does not exist: Sent',
            'TAG4 NO [ALREADYEXISTS] Mailbox already exists',
            '+ Ready for literal',
            'TAG5 OK [APPENDUID 1 1] Append completed',
        ]);

        $result = $this->send($this->findUser(), $this->mail());

        self::assertSame(['status' => 'saved', 'mailbox' => 'Sent'], $result['sent_copy']);
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function copyFailures(): iterable
    {
        yield 'over quota' => [['TAG1 OK Logged in', '* LIST (\HasNoChildren \Sent) "." Sent', 'TAG2 OK List completed', '+ Ready for literal', 'TAG3 NO [OVERQUOTA] Quota exceeded'], false];
        yield 'too many sessions' => [['TAG1 NO [LIMIT] Maximum number of connections from user+IP exceeded'], true];
        yield 'connection lost' => [[], true];
    }

    /**
     * @param list<string> $script
     */
    #[DataProvider('copyFailures')]
    public function testAFailedCopyIsReportedButIsNotAFailedSend(array $script, bool $temporary): void
    {
        $this->servers(imap: $script);

        $result = $this->send($this->findUser(), $this->mail());

        self::assertTrue($result['sent']);
        self::assertSame('failed', $result['sent_copy']['status']);
        self::assertSame($temporary, $result['sent_copy']['temporary']);
        self::assertStringContainsString('Do not send it again', $result['sent_copy']['reason']);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    public function testAnyOtherFailureOfTheCopyIsNotAFailedSendEither(): void
    {
        $this->servers();
        self::assertInstanceOf(FakeImapServer::class, FakeImapServer::$current);
        FakeImapServer::$current->onLogin = static function (): void {
            throw new \RuntimeException('Something unexpected');
        };

        $result = $this->send($this->findUser(), $this->mail());

        self::assertTrue($result['sent']);
        self::assertSame('failed', $result['sent_copy']['status']);
    }

    public function testSendOnlyUsersSendWithoutACopy(): void
    {
        $user = $this->createUser('sender');
        $user->setSendOnly(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $smtp = $this->servers();

        $result = $this->send($user, $this->mail());

        self::assertSame(['cred#sender@example.com'], $smtp->logins);
        self::assertTrue($result['sent']);
        self::assertSame('skipped', $result['sent_copy']['status']);
        self::assertSame(0, FakeImapServer::$current?->connections);
        self::assertSame(0, $this->shortLivedCredentialCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidMails(): iterable
    {
        yield 'no recipient' => [['to' => [], 'subject' => 's', 'body' => 'b']];
        yield 'an invalid recipient' => [['to' => ['not an address'], 'subject' => 's', 'body' => 'b']];
        yield 'a recipient smuggling a command' => [['to' => ["bob@example.com>\r\nRCPT TO:<eve@example.com"], 'subject' => 's', 'body' => 'b']];
        yield 'a recipient with a display name' => [['to' => ['Bob <bob@example.com>'], 'subject' => 's', 'body' => 'b']];
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
        $smtp = $this->servers();

        $this->sendExpectingError($this->findUser(), $mail);

        self::assertSame(0, $smtp->connections);
    }

    public function testANonAsciiDomainGoesOutEncoded(): void
    {
        $smtp = $this->servers();

        $this->send($this->findUser(), [...$this->mail(), 'to' => ['bob@bücher.example.com']]);

        self::assertSame(['bob@xn--bcher-kva.example.com'], $smtp->rcptTo);
    }

    public function testTooManyRecipientsAreRefusedAcrossAllFields(): void
    {
        $this->limit('MCP_MAIL_SEND_MAX_RECIPIENTS', '2');
        $smtp = $this->servers();

        $result = $this->sendExpectingError($this->findUser(), [
            ...$this->mail(),
            'to' => ['a@example.com'],
            'cc' => ['b@example.com'],
            'bcc' => ['c@example.com'],
        ]);

        self::assertStringContainsString('at most 2 recipients', $result);
        self::assertSame(0, $smtp->connections);
    }

    public function testATooLargeMessageIsRefused(): void
    {
        $this->limit('MCP_MAIL_SEND_MAX_BYTES', '1024');
        $smtp = $this->servers();

        $result = $this->sendExpectingError($this->findUser(), [...$this->mail(), 'body' => str_repeat('x', 2048)]);

        self::assertStringContainsString('1024 bytes', $result);
        self::assertSame(0, $smtp->connections);
    }

    public function testTheRateLimitPerTokenRefusesWithoutConnecting(): void
    {
        $this->limit('MCP_MAIL_SEND_TOKEN_LIMIT_PER_HOUR', '1');
        $smtp = $this->servers();
        $user = $this->findUser();
        $secret = $this->storeToken($user, [ApiTokenScope::MailSend]);
        $session = $this->initialiseSession($secret);

        $first = $this->callTool($secret, $session, 'message_send', $this->mail());
        self::assertFalse($first['isError'] ?? false, (string) json_encode($first));

        $second = $this->callTool($secret, $session, 'message_send', $this->mail());
        self::assertTrue($second['isError'] ?? false);
        self::assertMatchesRegularExpression('/not sent.*Retry in \d+ seconds/', $second['content'][0]['text']);
        self::assertSame(1, $smtp->connections);

        $this->servers();
        $otherToken = $this->send($user, $this->mail());
        self::assertTrue($otherToken['sent'], 'Another token of the user has a limit of its own.');
    }

    public function testTheRateLimitPerUserCoversAllTokensOfTheUser(): void
    {
        $this->limit('MCP_MAIL_SEND_USER_LIMIT_PER_HOUR', '1');
        $smtp = $this->servers();
        $user = $this->findUser();

        $this->send($user, $this->mail());
        $this->sendExpectingError($user, $this->mail());
        self::assertSame(1, $smtp->connections);

        $this->servers();
        $other = $this->send($this->createUser('another'), $this->mail());
        self::assertTrue($other['sent'], 'Another user has a limit of their own.');
    }

    public function testTheGlobalLimitCoversAllUsers(): void
    {
        // A third of it is available at once, see MailSendRateLimiter.
        $this->limit('MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE', '3');
        $smtp = $this->servers();

        $this->send($this->findUser(), $this->mail());
        $result = $this->sendExpectingError($this->createUser('another'), $this->mail());

        self::assertMatchesRegularExpression('/not sent.*Retry in \d+ seconds/', $result);
        self::assertSame(1, $smtp->connections);
    }

    public function testAuditLogHasRecipientsAndMessageIdButNotTheContent(): void
    {
        $this->servers();

        $result = $this->send($this->findUser(), [
            'to' => ['bob@example.com'],
            'cc' => ['carol@example.com'],
            'bcc' => ['hidden@example.com'],
            'subject' => 'Private subject',
            'body' => 'the secret plan',
        ]);

        $record = $this->auditRecords()[0];
        self::assertSame('ok', $record->context['result']);
        self::assertSame(['bob@example.com', 'carol@example.com', 'hidden@example.com'], $record->context['recipients']);
        self::assertSame($result['message_id'], $record->context['message_id']);
        self::assertSame('saved', $record->context['sent_copy']);
        self::assertStringNotContainsString('secret plan', (string) json_encode($record->toArray()));
        self::assertStringNotContainsString('Private subject', (string) json_encode($record->toArray()));
    }

    public function testAuditLogRecordsAFailedSendWithItsRecipients(): void
    {
        $this->servers(['RCPT' => '550 5.1.1 No such user']);

        $this->sendExpectingError($this->findUser(), $this->mail());

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
        $records = array_values(array_filter($handler->getRecords(), static fn (LogRecord $record): bool => 'message_send' === ($record->context['tool'] ?? null)));
        self::assertCount(1, $records);

        return $records;
    }

    /**
     * @param array<string, string> $replies
     * @param list<string>          $imap
     */
    private function servers(array $replies = [], array $imap = self::SAVED_IN_SENT): FakeSmtpServer
    {
        FakeImapServer::$current = new FakeImapServer($imap);

        return FakeSmtpServer::$current = new FakeSmtpServer($replies);
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
     * @return array{sent: bool, message_id: string, from: string, recipients: list<string>, sent_copy: array{status: 'saved', mailbox: string}|array{status: 'skipped', reason: string}|array{status: 'failed', temporary: bool, reason: string}}
     */
    private function send(User $user, array $arguments): array
    {
        // Whatever the test holds may belong to an entity manager from before a kernel reboot.
        $secret = $this->storeToken($this->findUser((string) $user), [ApiTokenScope::MailSend]);

        return $this->asSentMail($this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'message_send', $arguments)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function sendExpectingError(User $user, array $arguments): string
    {
        // Whatever the test holds may belong to an entity manager from before a kernel reboot.
        $secret = $this->storeToken($this->findUser((string) $user), [ApiTokenScope::MailSend]);
        $result = $this->callTool($secret, $this->initialiseSession($secret), 'message_send', $arguments);

        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));

        return $result['content'][0]['text'];
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
}
