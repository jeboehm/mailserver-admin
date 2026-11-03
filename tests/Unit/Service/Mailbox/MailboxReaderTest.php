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
use App\Service\Mailbox\BodyDecoder;
use App\Service\Mailbox\DTO\MessageCriteria;
use App\Service\Mailbox\HtmlToText;
use App\Service\Mailbox\MailboxReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Helper\FakeImapServer;

/**
 * Mailbox names and search values come straight from tool arguments, so these tests look at what
 * reaches the server. An IMAP command is one line: another line would be another command.
 */
class MailboxReaderTest extends TestCase
{
    use ImapFixtureTrait;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostileMailboxNames(): iterable
    {
        yield 'a command after a line break' => ["x\r\nTAG9 DELETE INBOX", '"x&AA0ACg-TAG9 DELETE INBOX"'];
        yield 'closing the quoted string' => ['x" (\Seen) "y', '"x\" (\\\\Seen) \"y"'];
        yield 'a trailing backslash' => ['x\\', '"x\\\\"'];
        yield 'a literal marker' => ['{5}', '"{5}"'];
        yield 'a NUL byte' => ["x\x00y", '"x&AAA-y"'];
        yield 'non-ASCII' => ['Prüfung', '"Pr&APw-fung"'];
    }

    #[DataProvider('hostileMailboxNames')]
    public function testAMailboxNameStaysOneQuotedArgument(string $mailbox, string $onTheWire): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in', 'TAG2 NO [NONEXISTENT] Unknown mailbox']);

        try {
            $this->reader($server)->get($this->mailUser(), $mailbox, 1);
            self::fail('Expected the mailbox to be refused.');
        } catch (MailboxDeniedException) {
        }

        self::assertSame('TAG2 EXAMINE ' . $onTheWire, $server->commands[1]);
        self::assertCount(2 + 1, $server->commands, 'LOGIN, EXAMINE and LOGOUT, and nothing else.');
    }

    public function testASearchValueGoesAsACountedLiteral(): void
    {
        $value = "x\r\nTAG9 DELETE INBOX";
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            'TAG2 OK [READ-ONLY] Examined',
            '+ Ready for literal',
            '* SEARCH',
            'TAG3 OK Searched',
        ]);

        $page = $this->reader($server)->search($this->mailUser(), 'INBOX', new MessageCriteria(text: $value), 10, 0);

        self::assertSame(0, $page->total);
        self::assertSame(\sprintf('TAG3 UID SEARCH CHARSET UTF-8 TEXT {%d}', \strlen($value)), $server->commands[2]);
        self::assertSame($value, $server->commands[3]);
    }

    public function testAMissingMailboxLooksLikeAnyOtherDenial(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in', 'TAG2 NO [NONEXISTENT] Unknown mailbox']);

        $this->expectException(MailboxDeniedException::class);

        $this->reader($server)->search($this->mailUser(), 'Nope', new MessageCriteria(), 10, 0);
    }

    public function testAMailboxNamedLikeARetryableAnswerIsStillDenied(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in', 'TAG2 NO [NONEXISTENT] Unknown mailbox']);

        $this->expectException(MailboxDeniedException::class);

        $this->reader($server)->search($this->mailUser(), '[INUSE]', new MessageCriteria(), 10, 0);
    }

    public function testABusyMailboxIsReportedAsRetryable(): void
    {
        $server = new FakeImapServer(['TAG1 OK Logged in', 'TAG2 NO [INUSE] Mailbox is busy']);

        try {
            $this->reader($server)->search($this->mailUser(), 'INBOX', new MessageCriteria(), 10, 0);
            self::fail('Expected the mailbox to be unavailable.');
        } catch (MailboxUnavailableException $e) {
            self::assertTrue($e->retryable);
        }
    }

    public function testExaminesAndNeverSelects(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            'TAG2 OK [READ-ONLY] Examined',
            'TAG3 OK Fetched',
        ]);

        try {
            $this->reader($server)->get($this->mailUser(), 'INBOX', 1);
            self::fail('Expected the missing message to be refused.');
        } catch (MailboxDeniedException) {
        }

        self::assertStringContainsString('EXAMINE', $server->sent());
        self::assertStringNotContainsString('SELECT', $server->sent());
        self::assertStringNotContainsString('BODY[', $server->sent());
    }

    public function testListsInboxFirstThenByNameAndSkipsCountersWhereThereAreNone(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\HasNoChildren) "/" Zebra',
            '* LIST (\Noselect) "/" Archive',
            '* LIST (\HasNoChildren) "/" INBOX',
            'TAG2 OK Listed',
            '* STATUS INBOX (MESSAGES 3 UNSEEN 1)',
            'TAG3 OK Status',
            '* STATUS Zebra (MESSAGES 0 UNSEEN 0)',
            'TAG4 OK Status',
        ]);

        $result = $this->reader($server)->listMailboxes($this->mailUser(), 10, 0);

        self::assertSame(3, $result['total']);
        self::assertSame(['INBOX', 'Archive', 'Zebra'], array_map(static fn ($mailbox): string => $mailbox->name, $result['mailboxes']));
        self::assertSame(3, $result['mailboxes'][0]->messages);
        self::assertNull($result['mailboxes'][1]->messages);
    }

    private function reader(FakeImapServer $server): MailboxReader
    {
        return new MailboxReader($this->sessions($server), new BodyDecoder(), new HtmlToText(), 65536);
    }
}
