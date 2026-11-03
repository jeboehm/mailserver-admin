<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Fixture;

use App\Service\Mailbox\ImapConnectionFactoryInterface;
use App\Service\Mailbox\TlsImapConnectionFactory;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use Tests\Integration\Helper\FakeImapServer;

/**
 * Takes the place of the real factory in the test environment. Connects to the current
 * FakeImapServer, or for real when there is none, which is what the end-to-end test relies on.
 */
final readonly class FakeImapConnectionFactory implements ImapConnectionFactoryInterface
{
    public function __construct(private TlsImapConnectionFactory $real)
    {
    }

    #[\Override]
    public function connect(): ImapConnection
    {
        $server = FakeImapServer::$current;

        if (null === $server) {
            return $this->real->connect();
        }

        $connection = new ImapConnection($server);
        $connection->connect('fake.test', 993);

        return $connection;
    }
}
