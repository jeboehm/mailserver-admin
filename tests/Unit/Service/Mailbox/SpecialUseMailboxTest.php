<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Mailbox;

use App\Service\Mailbox\SpecialUseMailbox;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Helper\FakeImapServer;

class SpecialUseMailboxTest extends TestCase
{
    use ImapFixtureTrait;

    private const string MESSAGE = "Subject: Hello\r\n\r\nsentinel body\r\n";

    public function testAppendsToTheMarkedMailboxWithTheGivenFlags(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Drafts) "/" Entwürfe',
            'TAG2 OK Listed',
            '+ Ready for literal',
            'TAG3 OK Appended',
        ]);

        $mailbox = $this->sessions($server)->run($this->mailUser(), static fn (ImapConnection $c): string => new SpecialUseMailbox()
            ->append($c, '\drafts', 'Drafts', self::MESSAGE, ['\Draft', '\Seen']));

        self::assertSame('Entwürfe', $mailbox);
        self::assertStringContainsString('(\Draft \Seen) {', $server->sent());
    }

    public function testCreatesTheDefaultMailboxWhenNoneIsMarked(): void
    {
        $server = new FakeImapServer([
            'TAG1 OK Logged in',
            '* LIST (\Sent) "/" Sent',
            'TAG2 OK Listed',
            'TAG3 NO [TRYCREATE] Mailbox does not exist',
            'TAG4 OK Created',
            '+ Ready for literal',
            'TAG5 OK Appended',
        ]);

        $mailbox = $this->sessions($server)->run($this->mailUser(), static fn (ImapConnection $c): string => new SpecialUseMailbox()
            ->append($c, '\drafts', 'Drafts', self::MESSAGE, ['\Draft']));

        self::assertSame('Drafts', $mailbox);
        self::assertStringContainsString('CREATE "Drafts"', $server->sent());
    }
}
