<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Mailbox;

use App\Exception\Mailbox\MailboxDeniedException;
use App\Exception\Mailbox\MailboxUnavailableException;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Helper\FakeImapServer;

class ImapSessionsTest extends TestCase
{
    use ImapFixtureTrait;

    public function testOperationGetsALoggedInConnectionAndItsResultComesBack(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in']);

        $expected = new \stdClass();

        $result = $this->sessions($server)->run($this->mailUser(), static fn (ImapConnection $connection): \stdClass => $expected);

        self::assertSame($expected, $result);
        self::assertStringStartsWith('TAG1 LOGIN "cred#alice@example.com" "', $server->commands[0]);
    }

    public function testADisabledUserIsDeniedWithoutAConnection(): void
    {
        $user = $this->mailUser();
        $user->setEnabled(false);
        $server = new FakeImapServer([]);

        $this->expectException(MailboxDeniedException::class);

        try {
            $this->sessions($server)->run($user, static fn (): null => null);
        } finally {
            self::assertSame(0, $server->connections);
        }
    }

    public function testASendOnlyUserIsDeniedWithoutAConnection(): void
    {
        $user = $this->mailUser();
        $user->setSendOnly(true);
        $server = new FakeImapServer([]);

        $this->expectException(MailboxDeniedException::class);

        try {
            $this->sessions($server)->run($user, static fn (): null => null);
        } finally {
            self::assertSame(0, $server->connections);
        }
    }

    public function testTooManySessionsIsRetryableWithoutAConnection(): void
    {
        $server = new FakeImapServer([]);

        try {
            $this->sessions($server, liveCredentials: 6)->run($this->mailUser(), static fn (): null => null);
            self::fail('Expected the session to be refused.');
        } catch (MailboxUnavailableException $e) {
            self::assertTrue($e->retryable);
        }

        self::assertSame(0, $server->connections);
    }

    public function testARefusedLoginLogsNeitherTheSecretNorTheLogin(): void
    {
        $server = new FakeImapServer(['TAG1 NO [AUTHENTICATIONFAILED] Authentication failed.']);

        try {
            $this->sessions($server)->run($this->mailUser(), static fn (): null => null);
            self::fail('Expected the login to fail.');
        } catch (MailboxUnavailableException $e) {
            self::assertFalse($e->retryable);
        }

        preg_match('/^TAG1 LOGIN "[^"]+" "([0-9a-f]+)"$/', $server->commands[0], $sent);
        self::assertArrayHasKey(1, $sent, 'The secret goes as a quoted string, on the first line.');

        $log = $this->loggedImapMessages();
        self::assertStringContainsString('AUTHENTICATIONFAILED', $log);
        self::assertStringNotContainsString($sent[1], $log);
        self::assertStringNotContainsString('cred#', $log);
    }

    public function testAFailedCommandLogsTheAnswerButNotWhatTheCommandCarried(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in', 'TAG2 NO [OVERQUOTA] Quota exceeded']);

        try {
            $this->sessions($server)->run($this->mailUser(), static function (ImapConnection $connection): void {
                $connection->append('Sent', "Subject: secret subject\r\n\r\nsentinel body", ['\Seen']);
            });
            self::fail('Expected the command to fail.');
        } catch (MailboxUnavailableException) {
        }

        $log = $this->loggedImapMessages();
        self::assertStringContainsString('APPEND', $log);
        self::assertStringContainsString('OVERQUOTA', $log);
        self::assertStringNotContainsString('sentinel body', $log);
        self::assertStringNotContainsString('secret subject', $log);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function serverAnswers(): iterable
    {
        yield 'connection limit' => ['TAG2 NO [LIMIT] Too many connections', true];
        yield 'busy mailbox' => ['TAG2 NO [INUSE] Mailbox is busy', true];
        yield 'server trouble' => ['TAG2 NO [SERVERBUG] Internal error', true];
        yield 'plain refusal' => ['TAG2 NO [NONEXISTENT] Unknown mailbox', false];
    }

    #[DataProvider('serverAnswers')]
    public function testOnlyTheServersAnswerDecidesWhetherToRetry(string $answer, bool $retryable): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in', $answer]);

        try {
            $this->sessions($server)->run($this->mailUser(), static function (ImapConnection $connection): void {
                $connection->examine('INBOX');
            });
            self::fail('Expected the command to fail.');
        } catch (MailboxUnavailableException $e) {
            self::assertSame($retryable, $e->retryable);
        }
    }

    public function testACallersTextCannotMakeAFailureLookRetryable(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in', 'TAG2 NO [NONEXISTENT] Unknown mailbox']);

        try {
            $this->sessions($server)->run($this->mailUser(), static function (ImapConnection $connection): void {
                $connection->examine('[LIMIT]');
            });
            self::fail('Expected the command to fail.');
        } catch (MailboxUnavailableException $e) {
            self::assertFalse($e->retryable);
        }
    }

    public function testAServerThatStopsAnsweringIsRetryable(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in']);

        try {
            $this->sessions($server)->run($this->mailUser(), static function (ImapConnection $connection): void {
                $connection->noop();
            });
            self::fail('Expected the session to fail.');
        } catch (MailboxUnavailableException $e) {
            self::assertTrue($e->retryable);
        }
    }

    public function testTheOperationsOwnExceptionsReachTheCaller(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in']);

        $this->expectException(\LogicException::class);

        $this->sessions($server)->run($this->mailUser(), static function (): void {
            throw new \LogicException('from the operation');
        });
    }
}
