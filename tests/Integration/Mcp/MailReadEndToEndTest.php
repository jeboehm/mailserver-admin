<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Mcp\ToolDeniedException;
use App\Repository\UserRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Mailbox\TlsImapConnectionFactory;
use App\Service\MailboxCredential\MailboxCredentials;
use App\Service\MailboxCredential\MailboxProtocol;
use App\Service\MailboxCredential\ShortLivedCredential;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\McpClientTrait;

/**
 * The mail read tools against a real docker-mailserver, including the messages that crash Doveadm
 * (#550). Skipped unless MAIL_E2E is set, as it needs a stack and writes to its database.
 *
 * The stack needs the users of its test/fixtures.sh, plus bob@example.com with password test1234.
 * Point the test at it:
 *
 *     MAIL_E2E=1 MDA_IMAPS_ADDRESS=127.0.0.1:<port> DATABASE_URL=<the stack's database> \
 *         vendor/bin/phpunit tests/Integration/Mcp/MailReadEndToEndTest.php
 *
 * No rollback: Dovecot has to see the credentials, so they must be committed.
 */
#[SkipDatabaseRollback]
class MailReadEndToEndTest extends WebTestCase
{
    use McpClientTrait;

    private const string OWNER = 'admin@example.com';
    private const string OWNER_PASSWORD = 'changeme';
    private const string OTHER = 'bob@example.com';

    private static string $mailbox;

    /** @var array<string, int> uid by message name */
    private static array $uids = [];

    /** @var list<string> */
    private array $tokenSecrets = [];

    public static function setUpBeforeClass(): void
    {
        if (false === (getenv('MAIL_E2E') ?: ($_SERVER['MAIL_E2E'] ?? false))) {
            return;
        }

        self::$mailbox = 'E2E-' . bin2hex(random_bytes(4)) . '-Prüfung';
    }

    public static function tearDownAfterClass(): void
    {
        if (false === (getenv('MAIL_E2E') ?: ($_SERVER['MAIL_E2E'] ?? false)) || [] === self::$uids) {
            return;
        }

        [$host, $port] = explode(':', (string) ($_SERVER['MDA_IMAPS_ADDRESS'] ?? getenv('MDA_IMAPS_ADDRESS')), 2);
        $connection = new ImapConnection(new \DirectoryTree\ImapEngine\Connection\Streams\ImapStream());
        $connection->connect($host, (int) $port, ['encryption' => 'ssl', 'validate_cert' => false, 'timeout' => 30]);
        $connection->login(self::OWNER, self::OWNER_PASSWORD);
        $connection->delete(\DirectoryTree\ImapEngine\Support\Str::toImapUtf7(self::$mailbox));
        $connection->logout();
        $connection->disconnect();
    }

    #[\Override]
    protected function setUp(): void
    {
        if (false === (getenv('MAIL_E2E') ?: ($_SERVER['MAIL_E2E'] ?? false))) {
            self::markTestSkipped('Needs a real stack, see the class comment.');
        }

        parent::setUp();

        $this->enableMcp();
        $this->setEnvironment('MCP_MAIL_READ_ENABLED', 'true');
        $this->client = static::createClient();

        if ([] === self::$uids) {
            $this->seedMailbox();
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        foreach ($this->tokenSecrets as $secret) {
            $token = $entityManager->getRepository(ApiToken::class)->findOneBy(['secretHash' => ApiToken::hashSecret($secret)]);

            if (null !== $token) {
                $entityManager->remove($token);
            }
        }

        $entityManager->flush();
        $this->unsetEnvironment('MCP_ENABLED');
        $this->unsetEnvironment('MCP_MAIL_READ_ENABLED');

        parent::tearDown();
    }

    public function testMailboxListSeesTheNonAsciiMailbox(): void
    {
        $result = $this->tool(self::OWNER, 'mailbox_list', ['limit' => 100]);

        self::assertContains(self::$mailbox, array_column($result['mailboxes'], 'name'));
        self::assertSame('INBOX', $result['mailboxes'][0]['name']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodies(): iterable
    {
        yield '64 KiB German in 8bit' => ['german', 'Grüße aus Köln'];
        yield '48 KiB Japanese in 8bit' => ['japanese', 'こんにちは世界'];
        yield '64 KiB Latin-1 in 8bit' => ['latin1', 'Grüße aus Köln'];
        yield 'quoted-printable Latin-1' => ['qp_latin1', 'Grüße aus Köln'];
        yield 'base64 Japanese' => ['base64_japanese', 'こんにちは世界'];
        yield 'multipart with a 25 MiB attachment' => ['attachment', 'Rechnung für März'];
    }

    #[DataProvider('bodies')]
    public function testNonAsciiBodiesAreReadIntact(string $message, string $expected): void
    {
        $result = $this->tool(self::OWNER, 'message_get', ['mailbox' => self::$mailbox, 'uid' => self::$uids[$message]]);

        self::assertStringContainsString($expected, $result['untrusted_external_content']['body']);
        self::assertStringNotContainsString("\u{FFFD}", $result['untrusted_external_content']['body']);
    }

    public function testASingleNonAsciiByteAtTheBufferBoundaryIsRead(): void
    {
        $result = $this->tool(self::OWNER, 'message_get', ['mailbox' => self::$mailbox, 'uid' => self::$uids['megabyte']]);
        $body = $result['untrusted_external_content']['body'];

        self::assertSame('ü', substr($body, 8191, 2));
        self::assertTrue($result['body_truncated'], 'The message is 1 MiB and the limit far less.');
        self::assertLessThanOrEqual(65536, \strlen($body));
    }

    public function testRandomBytesStillGiveValidOutput(): void
    {
        $result = $this->tool(self::OWNER, 'message_get', ['mailbox' => self::$mailbox, 'uid' => self::$uids['random']]);

        self::assertTrue(mb_check_encoding($result['untrusted_external_content']['body'], 'UTF-8'));
    }

    public function testAttachmentsAreListedWithoutTheirContent(): void
    {
        $peakBefore = memory_get_peak_usage(true);

        $result = $this->tool(self::OWNER, 'message_get', ['mailbox' => self::$mailbox, 'uid' => self::$uids['attachment']]);

        $attachments = $result['untrusted_external_content']['attachments'];
        self::assertCount(1, $attachments);
        self::assertSame('Rechnung März.pdf', $attachments[0]['filename']);
        self::assertSame('application/pdf', $attachments[0]['content_type']);
        self::assertGreaterThan(25 * 1024 * 1024, $attachments[0]['size']);
        self::assertLessThan(1024 * 1024, \strlen((string) json_encode($result)));
        self::assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $peakBefore, 'The attachment must not be fetched.');
    }

    public function testHtmlOnlyMailIsConverted(): void
    {
        $result = $this->tool(self::OWNER, 'message_get', ['mailbox' => self::$mailbox, 'uid' => self::$uids['html']]);

        self::assertTrue($result['body_converted_from_html']);
        self::assertStringContainsString('Hallo Jörg', $result['untrusted_external_content']['body']);
        self::assertStringNotContainsString('<p>', $result['untrusted_external_content']['body']);
    }

    public function testSearchFindsNonAsciiAndPaginatesNewestFirst(): void
    {
        $found = $this->tool(self::OWNER, 'message_search', ['mailbox' => self::$mailbox, 'subject' => 'März']);
        self::assertSame(1, $found['total']);
        self::assertSame(self::$uids['attachment'], $found['messages'][0]['uid']);
        self::assertSame('Rechnung für März', $found['messages'][0]['untrusted_external_content']['subject']);

        $page = $this->tool(self::OWNER, 'message_search', ['mailbox' => self::$mailbox, 'limit' => 2]);
        self::assertSame(\count(self::$uids), $page['total']);
        self::assertCount(2, $page['messages']);
        self::assertGreaterThan($page['messages'][1]['uid'], $page['messages'][0]['uid']);
    }

    public function testASearchValueIsNotASearchKey(): void
    {
        $found = $this->tool(self::OWNER, 'message_search', ['mailbox' => self::$mailbox, 'subject' => '" OR ALL "']);

        self::assertSame(0, $found['total']);
    }

    public function testReadsLeaveFlagsAndModseqUnchanged(): void
    {
        $before = $this->mailboxState();

        $this->tool(self::OWNER, 'mailbox_list', ['limit' => 100]);
        $this->tool(self::OWNER, 'message_search', ['mailbox' => self::$mailbox, 'text' => 'Köln']);

        foreach (self::$uids as $uid) {
            $this->tool(self::OWNER, 'message_get', ['mailbox' => self::$mailbox, 'uid' => $uid]);
        }

        self::assertSame($before, $this->mailboxState());
    }

    public function testTheFlagCheckWouldNoticeARead(): void
    {
        $uid = $this->appendMessage('control', $this->singlePart('text/plain; charset=utf-8', '8bit', 'Kontrolle', 'Kontrolle'));
        $before = $this->mailboxState();

        $this->withPassword(self::OWNER, self::OWNER_PASSWORD, static function (ImapConnection $connection) use ($uid): void {
            $connection->select(\DirectoryTree\ImapEngine\Support\Str::toImapUtf7(self::$mailbox));
            $connection->fetch(['UID', 'BODY[]'], $uid);
        });

        $after = $this->mailboxState();
        self::assertNotSame($before, $after);
        self::assertContains('\Seen', $after['flags'][$uid]);
    }

    public function testNoCredentialRemainsAfterAToolCall(): void
    {
        $this->tool(self::OWNER, 'message_search', ['mailbox' => self::$mailbox]);

        self::assertSame(0, $this->shortLivedRows());
    }

    public function testNoCredentialRemainsAfterAFailedToolCall(): void
    {
        $this->tool(self::OWNER, 'message_get', ['mailbox' => 'does not exist', 'uid' => 1], true);

        self::assertSame(0, $this->shortLivedRows());
    }

    public function testAnotherUserCannotReachTheMailbox(): void
    {
        $denied = $this->tool(self::OTHER, 'message_get', ['mailbox' => self::$mailbox, 'uid' => self::$uids['german']], true);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $denied['content'][0]['text']);

        $search = $this->tool(self::OTHER, 'message_search', ['subject' => 'Rechnung für März']);
        self::assertSame(0, $search['total']);

        $names = array_column($this->tool(self::OTHER, 'mailbox_list', ['limit' => 100])['mailboxes'], 'name');
        self::assertNotContains(self::$mailbox, $names);
    }

    public function testACredentialOnlyLogsInAsItsOwnUser(): void
    {
        $other = $this->user(self::OTHER);

        static::getContainer()->get(MailboxCredentials::class)->withCredential(
            $other,
            MailboxProtocol::Imap,
            static function (ShortLivedCredential $credential): void {
                $connection = static::getContainer()->get(TlsImapConnectionFactory::class)->connect();

                try {
                    $connection->login('cred#' . self::OWNER, $credential->secret);
                    self::fail('The credential of one user must not open the mailbox of another.');
                } catch (ImapCommandException $e) {
                    self::assertStringContainsString('AUTHENTICATIONFAILED', $e->getMessage());
                } finally {
                    $connection->disconnect();
                }
            },
        );
    }

    public function testACredentialOfADeletedRowNoLongerLogsIn(): void
    {
        $issued = static::getContainer()->get(MailboxCredentials::class)->withCredential(
            $this->user(self::OWNER),
            MailboxProtocol::Imap,
            static fn (ShortLivedCredential $credential): ShortLivedCredential => $credential,
        );

        $connection = static::getContainer()->get(TlsImapConnectionFactory::class)->connect();

        try {
            $connection->login($issued->login, $issued->secret);
            self::fail('A deleted credential must not log in.');
        } catch (ImapCommandException $e) {
            self::assertStringContainsString('AUTHENTICATIONFAILED', $e->getMessage());
        } finally {
            $connection->disconnect();
        }
    }

    public function testSendOnlyUsersCannotRead(): void
    {
        $result = $this->tool('sendonly@example.com', 'mailbox_list', [], true);

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
    }

    /**
     * Failed logins delay every next login from this address, up to 15 s. Last, as it slows others.
     */
    public function testACallOutlastsTheAuthPenaltyOfSomeoneElse(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $connection = static::getContainer()->get(TlsImapConnectionFactory::class)->connect();

            try {
                $connection->login(self::OTHER, 'mistyped');
            } catch (ImapCommandException) {
            } finally {
                $connection->disconnect();
            }
        }

        $result = $this->tool(self::OWNER, 'mailbox_list', ['limit' => 1]);

        self::assertGreaterThan(0, $result['total']);
        self::assertSame(0, $this->shortLivedRows());
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the decoded output, or the raw result when an error is expected
     */
    private function tool(string $email, string $tool, array $arguments, bool $expectError = false): array
    {
        $secret = $this->storeToken($this->user($email), [ApiTokenScope::MailRead]);
        $this->tokenSecrets[] = $secret;

        $result = $this->callTool($secret, $this->initialiseSession($secret), $tool, $arguments);

        if ($expectError) {
            self::assertTrue($result['isError'] ?? false, (string) json_encode($result));

            return $result;
        }

        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));

        return json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
    }

    private function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmailAddress($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function shortLivedRows(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM mail_mailbox_credentials WHERE kind = 'short_lived'",
        );
    }

    /**
     * What a separate session with the user's password sees: nothing here can be touched by the tools.
     *
     * @return array{flags: array<int, list<mixed>>, status: list<mixed>}
     */
    private function mailboxState(): array
    {
        return $this->withPassword(self::OWNER, self::OWNER_PASSWORD, static function (ImapConnection $connection): array {
            $mailbox = \DirectoryTree\ImapEngine\Support\Str::toImapUtf7(self::$mailbox);
            $status = $connection->status($mailbox, ['MESSAGES', 'UNSEEN', 'HIGHESTMODSEQ'])->tokenAt(3);
            self::assertInstanceOf(ListData::class, $status);

            $connection->examine($mailbox);

            $flags = [];

            foreach ($connection->fetch(['UID', 'FLAGS'], 1, \INF) as $response) {
                $data = $response->tokenAt(3);
                self::assertInstanceOf(ListData::class, $data);
                $itemFlags = $data->lookup('FLAGS');
                self::assertInstanceOf(ListData::class, $itemFlags);
                $flags[(int) $data->lookup('UID')?->value] = $itemFlags->values();
            }

            ksort($flags);

            return ['flags' => $flags, 'status' => $status->values()];
        });
    }

    /**
     * @template T
     *
     * @param callable(ImapConnection): T $operation
     *
     * @return T
     */
    private function withPassword(string $email, string $password, callable $operation): mixed
    {
        $connection = static::getContainer()->get(TlsImapConnectionFactory::class)->connect();

        try {
            $connection->login($email, $password);

            return $operation($connection);
        } finally {
            $connection->logout();
            $connection->disconnect();
        }
    }

    private function seedMailbox(): void
    {
        $this->withPassword(self::OWNER, self::OWNER_PASSWORD, static function (ImapConnection $connection): void {
            $connection->create(\DirectoryTree\ImapEngine\Support\Str::toImapUtf7(self::$mailbox));
        });

        $german = $this->lines('Grüße aus Köln. ', 64 * 1024);
        $japanese = $this->lines('こんにちは世界。', 48 * 1024);
        $latin1 = (string) iconv('UTF-8', 'ISO-8859-1', $this->lines('Grüße aus Köln. ', 64 * 1024));
        $megabyte = str_repeat('a', 8191) . 'ü' . str_repeat('a', 1024 * 1024);

        $this->appendMessage('german', $this->singlePart('text/plain; charset=utf-8', '8bit', 'German', $german));
        $this->appendMessage('japanese', $this->singlePart('text/plain; charset=utf-8', '8bit', 'Japanese', $japanese));
        $this->appendMessage('latin1', $this->singlePart('text/plain; charset=iso-8859-1', '8bit', 'Latin-1', $latin1));
        $this->appendMessage('qp_latin1', $this->singlePart('text/plain; charset=iso-8859-1', 'quoted-printable', 'QP', quoted_printable_encode((string) iconv('UTF-8', 'ISO-8859-1', 'Grüße aus Köln'))));
        $this->appendMessage('base64_japanese', $this->singlePart('text/plain; charset=utf-8', 'base64', 'Base64', chunk_split(base64_encode('こんにちは世界'))));
        $this->appendMessage('megabyte', $this->singlePart('text/plain; charset=utf-8', '8bit', 'Boundary', $megabyte));
        $this->appendMessage('random', $this->singlePart('text/plain; charset=utf-8', '8bit', 'Random', str_replace(["\r", "\n", "\0"], 'x', random_bytes(64 * 1024))));
        $this->appendMessage('html', $this->singlePart('text/html; charset=utf-8', '8bit', 'Html', '<html><body><p>Hallo Jörg</p></body></html>'));

        $attachment = chunk_split(base64_encode(random_bytes(25 * 1024 * 1024 / 4 * 3 + 1024)));
        $this->appendMessage('attachment', implode("\r\n", [
            'From: =?UTF-8?Q?J=C3=B6rg?= <joerg@example.test>',
            'To: admin@example.com',
            'Subject: =?UTF-8?Q?Rechnung_f=C3=BCr_M=C3=A4rz?=',
            'Date: Mon, 02 Nov 2026 10:00:00 +0100',
            'Message-ID: <attachment@example.test>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary=b',
            '',
            '--b',
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            'Rechnung für März',
            '--b',
            'Content-Type: application/pdf',
            "Content-Disposition: attachment; filename*=UTF-8''Rechnung%20M%C3%A4rz.pdf",
            'Content-Transfer-Encoding: base64',
            '',
            $attachment,
            '--b--',
            '',
        ]));
    }

    private function lines(string $sentence, int $bytes): string
    {
        return str_repeat($sentence . "\r\n", (int) ($bytes / (\strlen($sentence) + 2)));
    }

    private function singlePart(string $contentType, string $encoding, string $subject, string $body): string
    {
        return implode("\r\n", [
            'From: sender@example.test',
            'To: admin@example.com',
            'Subject: ' . $subject,
            'Date: Mon, 02 Nov 2026 10:00:00 +0100',
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType,
            'Content-Transfer-Encoding: ' . $encoding,
            '',
            $body,
        ]);
    }

    private function appendMessage(string $name, string $message): int
    {
        return self::$uids[$name] = $this->withPassword(self::OWNER, self::OWNER_PASSWORD, static function (ImapConnection $connection) use ($message): int {
            $response = $connection->append(\DirectoryTree\ImapEngine\Support\Str::toImapUtf7(self::$mailbox), $message);
            self::assertTrue($response->successful());
            self::assertMatchesRegularExpression('/APPENDUID \d+ (\d+)/', (string) $response);
            preg_match('/APPENDUID \d+ (\d+)/', (string) $response, $matches);

            return (int) $matches[1];
        });
    }
}
