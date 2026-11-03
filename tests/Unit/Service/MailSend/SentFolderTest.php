<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\MailSend;

use App\Service\MailSend\SentCopyStatus;
use App\Service\MailSend\SentFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Integration\Helper\FakeImapServer;
use Tests\Unit\Service\Mailbox\ImapFixtureTrait;

class SentFolderTest extends TestCase
{
    use ImapFixtureTrait;

    private const string MESSAGE = "Subject: Hello\r\n\r\nsentinel body\r\n";

    public function testSavesInTheMarkedMailbox(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\HasNoChildren \Sent) "/" Sent',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 OK Appended',
        ]);

        $copy = new SentFolder($this->sessions($server), new NullLogger())->save($this->mailUser(), self::MESSAGE);

        self::assertSame(SentCopyStatus::Saved, $copy->status);
        self::assertSame('Sent', $copy->mailbox);
        self::assertStringContainsString('TAG3 APPEND "Sent" (\Seen) {', $server->sent());
    }

    public function testPrefersSentThenTheFirstByNameAmongMarkedMailboxes(): void
    {
        $bothMarked = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Sent) "/" "Sent Messages"',
            '* LIST (\Sent) "/" Sent',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 OK Appended',
        ]);
        self::assertSame('Sent', new SentFolder($this->sessions($bothMarked), new NullLogger())->save($this->mailUser(), self::MESSAGE)->mailbox);

        $withoutSent = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Sent) "/" "Sent Messages"',
            '* LIST (\Sent) "/" Gesendet',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 OK Appended',
        ]);
        self::assertSame('Gesendet', new SentFolder($this->sessions($withoutSent), new NullLogger())->save($this->mailUser(), self::MESSAGE)->mailbox);
    }

    public function testCreatesTheMailboxWhenTheServerAsksForIt(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST () "/" INBOX',
            'TAG2 OK Listed',
            'TAG3 NO [TRYCREATE] Mailbox does not exist',
            'TAG4 OK Created',
            '+ Ready for literal',
            'TAG5 OK Appended',
        ]);

        $copy = new SentFolder($this->sessions($server), new NullLogger())->save($this->mailUser(), self::MESSAGE);

        self::assertSame(SentCopyStatus::Saved, $copy->status);
        self::assertSame('Sent', $copy->mailbox);
        self::assertStringContainsString('TAG4 CREATE "Sent"', $server->sent());
    }

    public function testAMailThatMentionsTryCreateDoesNotCreateAnything(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Sent) "/" Sent',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 NO [OVERQUOTA] Quota exceeded',
        ]);

        $copy = new SentFolder($this->sessions($server), new NullLogger())->save($this->mailUser(), "Subject: Hi\r\n\r\nIt said [TRYCREATE] and [LIMIT].\r\n");

        self::assertSame(SentCopyStatus::Failed, $copy->status);
        self::assertFalse($copy->temporary);
        self::assertStringNotContainsString('CREATE "Sent"', $server->sent());
    }

    public function testAUserWithoutMailboxAccessIsSkipped(): void
    {
        $user = $this->mailUser();
        $user->setSendOnly(true);
        $server = new FakeImapServer([]);

        $copy = new SentFolder($this->sessions($server), new NullLogger())->save($user, self::MESSAGE);

        self::assertSame(SentCopyStatus::Skipped, $copy->status);
        self::assertSame(0, $server->connections);
    }

    public function testAFailedAppendLeavesTheMailOutOfTheLog(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Sent) "/" Sent',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 NO [LIMIT] Too many',
        ]);

        $copy = new SentFolder($this->sessions($server), new NullLogger())->save($this->mailUser(), self::MESSAGE);

        self::assertSame(SentCopyStatus::Failed, $copy->status);
        self::assertTrue($copy->temporary);
        self::assertStringNotContainsString('sentinel body', $this->loggedImapMessages());
    }
}
