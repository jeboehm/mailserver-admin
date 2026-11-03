<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Mailbox;

use App\Service\Mailbox\ListedMailboxes;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use PHPUnit\Framework\TestCase;
use Tests\Integration\Helper\FakeImapServer;

class ListedMailboxesTest extends TestCase
{
    public function testReturnsTheAttributesByMailboxName(): void
    {
        $listed = ListedMailboxes::of($this->connectionAnswering([
            '* LIST (\HasNoChildren) "." INBOX',
            '* LIST (\HasNoChildren \Sent) "." "Sent Items"',
        ]));

        self::assertSame([
            'INBOX' => ['\HasNoChildren'],
            'Sent Items' => ['\HasNoChildren', '\Sent'],
        ], $listed);
    }

    public function testDecodesModifiedUtf7Names(): void
    {
        $listed = ListedMailboxes::of($this->connectionAnswering(['* LIST () "." "Entw&APw-rfe"']));

        self::assertSame(['Entwürfe' => []], $listed);
    }

    public function testNoMailboxesAtAll(): void
    {
        self::assertSame([], ListedMailboxes::of($this->connectionAnswering([])));
    }

    /**
     * @param list<string> $lines
     */
    private function connectionAnswering(array $lines): ImapConnection
    {
        $connection = new ImapConnection(new FakeImapServer([...$lines, 'TAG1 OK Listed']));
        $connection->connect('fake.test', 993);

        return $connection;
    }
}
