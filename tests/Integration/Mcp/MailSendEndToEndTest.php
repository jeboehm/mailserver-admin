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
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Mailbox\TlsImapConnectionFactory;
use App\Service\MailboxCredential\MailboxCredentials;
use App\Service\MailboxCredential\MailboxProtocol;
use App\Service\MailboxCredential\ShortLivedCredential;
use App\Service\MailSend\TlsSmtpTransportFactory;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Tests\Integration\Helper\DkimVerifier;
use Tests\Integration\Helper\McpClientTrait;

/**
 * message_send against a real docker-mailserver. Skipped unless MAIL_E2E is set, as it needs a stack
 * and writes to its database.
 *
 * The stack needs the users of its test/fixtures.sh, plus bob@example.com with password test1234,
 * and example.com's DKIM record in its unbound, as Rspamd does not sign without it (see
 * publish_dkim_record in its test/bats/integration/090_dkim.bats). Only use recipients on the stack's
 * own domains, so nothing leaves for the internet. Point the test at it, with Redis as well because
 * of the send limits:
 *
 *     MAIL_E2E=1 MDA_IMAPS_ADDRESS=127.0.0.1:<port> MTA_SMTP_SUBMISSION_ADDRESS=127.0.0.1:<port> \
 *         REDIS_HOST=127.0.0.1 REDIS_PORT=<port> REDIS_PASSWORD=<password> DATABASE_URL=<the stack's database> \
 *         vendor/bin/phpunit tests/Integration/Mcp/MailSendEndToEndTest.php
 *
 * No rollback: Dovecot and Postfix have to see the credentials, so they must be committed.
 */
#[SkipDatabaseRollback]
class MailSendEndToEndTest extends WebTestCase
{
    use McpClientTrait;

    private const string OWNER = 'admin@example.com';
    private const string OWNER_PASSWORD = 'changeme';
    private const string RECIPIENT = 'bob@example.com';
    private const string RECIPIENT_PASSWORD = 'test1234';
    private const string SEND_ONLY = 'sendonly@example.com';
    private const string OVER_QUOTA = 'quota@example.com';
    private const string OVER_QUOTA_PASSWORD = 'test1234';

    /** @var list<string> */
    private array $tokenSecrets = [];

    #[\Override]
    protected function setUp(): void
    {
        if (false === (getenv('MAIL_E2E') ?: ($_SERVER['MAIL_E2E'] ?? false))) {
            self::markTestSkipped('Needs a real stack, see the class comment.');
        }

        parent::setUp();

        $this->enableMcp();
        $this->setEnvironment('MCP_MAIL_SEND_ENABLED', 'true');
        // Postfix's own limit is what this test is up against, not ours.
        $this->setEnvironment('MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE', '1000');
        $this->client = static::createClient();
        static::getContainer()->get('app.mail_send_limit_pool')->clear();
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
        $this->unsetEnvironment('MCP_MAIL_SEND_ENABLED');
        $this->unsetEnvironment('MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE');

        parent::tearDown();
    }

    public function testASentMailArrivesWithAValidDkimSignatureAndACopyInAMailboxThatHadToBeCreated(): void
    {
        $this->deleteMailboxes(self::OWNER, self::OWNER_PASSWORD, ['Sent', 'Sent Messages']);

        $subject = 'Grüße ' . bin2hex(random_bytes(6));
        $result = $this->send(self::OWNER, [
            'to' => [self::RECIPIENT],
            'subject' => $subject,
            'body' => "Hallo Bob,\n\nschöne Grüße aus Köln.\n.\nEine Zeile mit nur einem Punkt.\n",
        ]);

        self::assertTrue($result['sent']);
        self::assertSame(['status' => 'saved', 'mailbox' => 'Sent'], $result['sent_copy']);

        $delivered = $this->waitForMessage(self::RECIPIENT, self::RECIPIENT_PASSWORD, 'INBOX', $result['message_id']);

        self::assertStringContainsString('From: admin@example.com', $delivered);
        self::assertStringContainsString('Message-ID: ' . $result['message_id'], $delivered);

        $tags = DkimVerifier::verify($delivered, 'example.com', $this->publicKey('example.com'));
        self::assertSame(['d' => 'example.com', 's' => 'dkim'], $tags);

        $copy = $this->fetchMessage(self::OWNER, self::OWNER_PASSWORD, 'Sent', $result['message_id']);
        self::assertNotNull($copy, 'The copy must be in Sent.');
        self::assertContains('\Seen', $copy['flags']);
        self::assertStringContainsString('Message-ID: ' . $result['message_id'], $copy['raw']);
        self::assertSame(0, $this->shortLivedRows());
    }

    /**
     * A signature that holds for any content would prove nothing about the one above.
     */
    public function testTheDkimCheckNoticesATamperedMail(): void
    {
        $result = $this->send(self::OWNER, ['to' => [self::RECIPIENT], 'subject' => 'Control ' . bin2hex(random_bytes(6)), 'body' => 'Control']);
        $delivered = $this->waitForMessage(self::RECIPIENT, self::RECIPIENT_PASSWORD, 'INBOX', $result['message_id']);
        $key = $this->publicKey('example.com');

        DkimVerifier::verify($delivered, 'example.com', $key);

        foreach ([str_replace('Control', 'Tampered', $delivered), str_replace('From: admin@', 'From: evil@', $delivered)] as $tampered) {
            try {
                DkimVerifier::verify($tampered, 'example.com', $key);
                self::fail('A changed mail must not verify.');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('does not match', $e->getMessage());
            }
        }
    }

    public function testBlindCopiesReachTheirMailboxAndNobodySeesThem(): void
    {
        $result = $this->send(self::OWNER, [
            'to' => [self::OWNER],
            'bcc' => [self::RECIPIENT],
            'subject' => 'Blind ' . bin2hex(random_bytes(6)),
            'body' => 'Blind',
        ]);

        $delivered = $this->waitForMessage(self::RECIPIENT, self::RECIPIENT_PASSWORD, 'INBOX', $result['message_id']);
        self::assertStringNotContainsString('Bcc:', $delivered);

        $copy = $this->fetchMessage(self::OWNER, self::OWNER_PASSWORD, 'Sent', $result['message_id']);
        self::assertNotNull($copy);
        self::assertStringContainsString('Bcc: ' . self::RECIPIENT, $copy['raw']);
    }

    public function testOfTwoSentMailboxesTheOneNamedSentIsUsed(): void
    {
        $this->withPassword(self::OWNER, self::OWNER_PASSWORD, static function (ImapConnection $connection): void {
            foreach (['Sent', 'Sent Messages'] as $mailbox) {
                try {
                    $connection->create($mailbox);
                } catch (ImapCommandException $e) {
                    self::assertStringContainsString('ALREADYEXISTS', $e->getMessage());
                }
            }

            $marked = [];

            foreach ($connection->list() as $response) {
                $attributes = $response->tokenAt(2);
                $name = $response->tokenAt(4);

                if ($attributes instanceof ListData && $name instanceof Token && \in_array('\Sent', $attributes->values(), true)) {
                    $marked[] = $name->value;
                }
            }

            sort($marked);
            self::assertSame(['Sent', 'Sent Messages'], $marked, 'A plain LIST must report both, or the choice cannot be made.');
        });

        $result = $this->send(self::OWNER, ['to' => [self::RECIPIENT], 'subject' => 'Which ' . bin2hex(random_bytes(6)), 'body' => 'Which one']);

        self::assertSame(['status' => 'saved', 'mailbox' => 'Sent'], $result['sent_copy']);
        self::assertNotNull($this->fetchMessage(self::OWNER, self::OWNER_PASSWORD, 'Sent', $result['message_id']));
        self::assertNull($this->fetchMessage(self::OWNER, self::OWNER_PASSWORD, 'Sent Messages', $result['message_id']));
    }

    public function testASendOnlyUserSendsWithoutACopy(): void
    {
        $result = $this->send(self::SEND_ONLY, ['to' => [self::RECIPIENT], 'subject' => 'Send only ' . bin2hex(random_bytes(6)), 'body' => 'No copy']);

        self::assertTrue($result['sent']);
        self::assertSame('skipped', $result['sent_copy']['status']);

        $delivered = $this->waitForMessage(self::RECIPIENT, self::RECIPIENT_PASSWORD, 'INBOX', $result['message_id']);
        DkimVerifier::verify($delivered, 'example.com', $this->publicKey('example.com'));
        self::assertSame(0, $this->shortLivedRows());
    }

    public function testACopyOverQuotaIsReportedAndTheMailIsStillSent(): void
    {
        $this->fillMailbox(self::OVER_QUOTA, self::OVER_QUOTA_PASSWORD);

        $result = $this->send(self::OVER_QUOTA, ['to' => [self::RECIPIENT], 'subject' => 'Quota ' . bin2hex(random_bytes(6)), 'body' => 'Over quota']);

        self::assertTrue($result['sent']);
        self::assertSame('failed', $result['sent_copy']['status']);
        self::assertFalse($result['sent_copy']['temporary']);
        $this->waitForMessage(self::RECIPIENT, self::RECIPIENT_PASSWORD, 'INBOX', $result['message_id']);
        self::assertSame(0, $this->shortLivedRows());
    }

    /**
     * The tool cannot name a sender, so this goes one level down: Postfix holds the credential to
     * its user's address as it does a password.
     */
    public function testACredentialCannotSendFromAForeignAddress(): void
    {
        static::getContainer()->get(MailboxCredentials::class)->withCredential(
            $this->user(self::OWNER),
            MailboxProtocol::Smtp,
            static function (ShortLivedCredential $credential): void {
                $transport = static::getContainer()->get(TlsSmtpTransportFactory::class)->create($credential);

                try {
                    $transport->send(new Email()->from(self::RECIPIENT)->to(self::RECIPIENT)->subject('Forged')->text('Forged'));
                    self::fail('A foreign sender must be refused.');
                } catch (TransportExceptionInterface $e) {
                    self::assertSame(553, $e->getCode());
                } finally {
                    $transport->stop();
                }
            },
        );
    }

    /**
     * Postfix allows 20 connections per minute from one address. Last, as it blocks sending for up
     * to a minute.
     */
    public function testPostfixsConnectionLimitIsReportedAsRetryable(): void
    {
        $address = (string) ($_SERVER['MTA_SMTP_SUBMISSION_ADDRESS'] ?? getenv('MTA_SMTP_SUBMISSION_ADDRESS'));

        for ($i = 0; $i < 25; ++$i) {
            $socket = stream_socket_client('tcp://' . $address, timeout: 30);
            self::assertIsResource($socket);
            $greeting = (string) fgets($socket);
            fclose($socket);

            if (str_starts_with($greeting, '421')) {
                break;
            }
        }

        self::assertStringStartsWith('421', $greeting, 'The limit should have been reached.');

        $secret = $this->storeToken($this->user(self::OWNER), [ApiTokenScope::MailSend]);
        $this->tokenSecrets[] = $secret;

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'message_send', [
            'to' => [self::RECIPIENT],
            'subject' => 'Limit',
            'body' => 'Limit',
        ]);

        self::assertTrue($result['isError'] ?? false, (string) json_encode($result));
        self::assertStringContainsString('was not sent', $result['content'][0]['text']);
        self::assertStringContainsString('Retry in a few seconds', $result['content'][0]['text']);
        self::assertSame(0, $this->shortLivedRows());
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function send(string $email, array $arguments): array
    {
        $secret = $this->storeToken($this->user($email), [ApiTokenScope::MailSend]);
        $this->tokenSecrets[] = $secret;

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'message_send', $arguments);
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));

        return json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
    }

    private function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmailAddress($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function publicKey(string $domainName): string
    {
        $domain = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Domain::class)->findOneBy(['name' => $domainName]);
        self::assertInstanceOf(Domain::class, $domain);

        $key = openssl_pkey_get_private($domain->getDkimPrivateKey());
        self::assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        return $details['key'];
    }

    private function shortLivedRows(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM mail_mailbox_credentials WHERE kind = 'short_lived'",
        );
    }

    /**
     * Delivery runs through Rspamd and LMTP, so it takes a moment.
     */
    private function waitForMessage(string $email, string $password, string $mailbox, string $messageId): string
    {
        for ($attempt = 0; $attempt < 60; ++$attempt) {
            $message = $this->fetchMessage($email, $password, $mailbox, $messageId);

            if (null !== $message) {
                return $message['raw'];
            }

            usleep(500000);
        }

        self::fail(\sprintf('%s did not reach %s.', $messageId, $email));
    }

    /**
     * @return array{raw: string, flags: list<mixed>}|null
     */
    private function fetchMessage(string $email, string $password, string $mailbox, string $messageId): ?array
    {
        return $this->withPassword($email, $password, static function (ImapConnection $connection) use ($mailbox, $messageId): ?array {
            $connection->examine($mailbox);

            $found = $connection->search(['HEADER', 'Message-ID', '"' . $messageId . '"'])->tokensAfter(2);

            if ([] === $found) {
                return null;
            }

            $response = $connection->fetch(['UID', 'FLAGS', 'BODY.PEEK[]'], (int) $found[0]->value)->first();
            $data = $response?->tokenAt(3);
            self::assertInstanceOf(ListData::class, $data);
            $flags = $data->lookup('FLAGS');
            self::assertInstanceOf(ListData::class, $flags);
            $raw = $data->lookup('[]');
            self::assertNotNull($raw);

            return ['raw' => $raw->value, 'flags' => $flags->values()];
        });
    }

    /**
     * @param list<string> $mailboxes
     */
    private function deleteMailboxes(string $email, string $password, array $mailboxes): void
    {
        $this->withPassword($email, $password, static function (ImapConnection $connection) use ($mailboxes): void {
            foreach ($mailboxes as $mailbox) {
                try {
                    $connection->delete($mailbox);
                } catch (ImapCommandException) {
                    // It did not exist.
                }
            }
        });
    }

    /**
     * Appends ever smaller messages until not even a small one fits, so the next copy cannot either.
     */
    private function fillMailbox(string $email, string $password): void
    {
        $this->withPassword($email, $password, static function (ImapConnection $connection): void {
            $lines = 1024;

            for ($attempt = 0; $attempt < 64 && $lines >= 1; ++$attempt) {
                try {
                    $connection->append('INBOX', "From: filler@example.com\r\nSubject: Filler\r\n\r\n" . str_repeat(str_repeat('x', 98) . "\r\n", $lines));
                } catch (ImapCommandException $e) {
                    self::assertStringContainsString('OVERQUOTA', $e->getMessage());
                    $lines = (int) ($lines / 2);
                }
            }

            self::assertSame(0, $lines, 'The mailbox has no quota.');
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
}
