<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use App\Service\MailboxCredential\ShortLivedCredential;
use App\Service\ServiceAddress;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Connects to Postfix's submission port with STARTTLS, and refuses to go on without it.
 *
 * Certificates are not verified, the same as Roundcube and TlsImapConnectionFactory do: the traffic
 * stays on the internal network, and the generated certificate has CN=mail.example.com and no SAN,
 * so it never matches the name "mta". No logger is passed, as the transport logs the SMTP dialogue.
 */
final readonly class TlsSmtpTransportFactory implements SmtpTransportFactory
{
    public function __construct(
        #[Autowire('%env(string:MTA_SMTP_SUBMISSION_ADDRESS)%')]
        private string $address,
        #[Autowire('%env(int:MTA_SMTP_TIMEOUT_SECONDS)%')]
        private int $timeoutSeconds,
    ) {
    }

    #[\Override]
    public function create(ShortLivedCredential $credential): EsmtpTransport
    {
        $address = ServiceAddress::parse($this->address, 587);

        $transport = Transport::fromDsn(\sprintf(
            'smtp://%s:%s@%s:%d?verify_peer=0&require_tls=1',
            rawurlencode($credential->login),
            rawurlencode($credential->secret),
            $address->host,
            $address->port,
        ));

        if (!$transport instanceof EsmtpTransport) {
            throw new \LogicException('An smtp:// DSN gives an ESMTP transport.');
        }

        // One attempt only. With the default list a refused login is tried again with the next
        // method, and every failure delays all further logins from this address.
        $transport->setAuthenticators([new PlainAuthenticator()]);

        // Has to outlast auth penalties, which delay a login by up to 15 seconds after failures by
        // anyone behind this address.
        $stream = $transport->getStream();

        if (!$stream instanceof SocketStream) {
            throw new \LogicException('An ESMTP transport from a DSN uses a socket.');
        }

        $stream->setTimeout($this->timeoutSeconds);

        return $transport;
    }
}
