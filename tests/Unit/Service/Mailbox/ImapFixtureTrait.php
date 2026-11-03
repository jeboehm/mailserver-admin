<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Mailbox;

use App\Entity\Domain;
use App\Entity\User;
use App\Service\Mailbox\ImapConnectionFactoryInterface;
use App\Service\Mailbox\ImapSessions;
use App\Service\MailboxCredential\MailboxCredentials;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Integration\Helper\FakeImapServer;

/**
 * Real ImapEngine commands against a scripted server, so these tests see what actually goes over the
 * wire and what the library's exceptions actually say. Only the database underneath is replaced.
 */
trait ImapFixtureTrait
{
    private TestHandler $imapLog;

    private function sessions(FakeImapServer $server, int $liveCredentials = 1): ImapSessions
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturn($liveCredentials);

        $this->imapLog = new TestHandler();

        return new ImapSessions(
            new MailboxCredentials($database, 5),
            new readonly class($server) implements ImapConnectionFactoryInterface {
                public function __construct(private FakeImapServer $server)
                {
                }

                #[\Override]
                public function connect(): ImapConnection
                {
                    $connection = new ImapConnection($this->server);
                    $connection->connect('fake.test', 993);

                    return $connection;
                }
            },
            new Logger('test', [$this->imapLog]),
        );
    }

    private function mailUser(): User
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $user = new User();
        $user->setName('alice');
        $user->setDomain($domain);
        $user->setEnabled(true);
        new \ReflectionProperty(User::class, 'id')->setValue($user, 7);

        return $user;
    }

    private function loggedImapMessages(): string
    {
        return implode("\n", array_map(
            static fn ($record): string => $record->message . ' ' . json_encode($record->context),
            $this->imapLog->getRecords(),
        ));
    }
}
