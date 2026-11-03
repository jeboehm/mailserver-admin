<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Fixture;

use App\Service\MailboxCredential\ShortLivedCredential;
use App\Service\MailSend\SmtpTransportFactoryInterface;
use App\Service\MailSend\TlsSmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\Integration\Helper\FakeSmtpServer;

/**
 * Takes the place of the real factory in the test environment. Connects to the current
 * FakeSmtpServer, or for real when there is none, which is what the end-to-end test relies on.
 */
final readonly class FakeSmtpTransportFactory implements SmtpTransportFactoryInterface
{
    public function __construct(private TlsSmtpTransportFactory $real)
    {
    }

    #[\Override]
    public function create(ShortLivedCredential $credential): EsmtpTransport
    {
        $server = FakeSmtpServer::$current;

        if (null === $server) {
            return $this->real->create($credential);
        }

        $transport = new EsmtpTransport('fake.test', 587, true, stream: $server, authenticators: [new PlainAuthenticator()]);
        $transport->setUsername($credential->login);
        $transport->setPassword($credential->secret);

        return $transport;
    }
}
