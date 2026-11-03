<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox;

use App\Service\ServiceAddress;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\ImapStream;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Connects to Dovecot with implicit TLS, because ImapEngine ignores validate_cert for STARTTLS.
 *
 * Certificates are not verified, the same as Roundcube does: the traffic stays on the internal
 * network, and the generated certificate has CN=mail.example.com and no SAN, so it never matches
 * the name "mda". No logger is passed to the connection, as ImapEngine's writes the secret in clear.
 */
final readonly class TlsImapConnectionFactory implements ImapConnectionFactory
{
    public function __construct(
        #[Autowire('%env(string:MDA_IMAPS_ADDRESS)%')]
        private string $address,
        #[Autowire('%env(int:MDA_IMAP_TIMEOUT_SECONDS)%')]
        private int $timeoutSeconds,
    ) {
    }

    #[\Override]
    public function connect(): ImapConnection
    {
        $address = ServiceAddress::parse($this->address, 993);

        $stream = new ImapStream();
        $connection = new ImapConnection($stream);
        $connection->connect($address->host, $address->port, [
            'encryption' => 'ssl',
            'validate_cert' => false,
            'timeout' => $this->timeoutSeconds,
        ]);

        // The option above only covers connecting. Everything after it has to outlast auth penalties,
        // which delay a login by up to 15 seconds after failures by anyone behind this address.
        $stream->setTimeout($this->timeoutSeconds);

        return $connection;
    }
}
