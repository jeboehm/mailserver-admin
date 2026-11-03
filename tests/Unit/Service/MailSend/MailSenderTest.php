<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\MailSend;

use App\Entity\Domain;
use App\Entity\User;
use App\Exception\Mailbox\MailboxDeniedException;
use App\Service\Mailbox\ImapConnectionFactory;
use App\Service\Mailbox\ImapSessions;
use App\Service\MailboxCredential\MailboxCredentials;
use App\Service\MailSend\MailComposer;
use App\Service\MailSend\MailSender;
use App\Service\MailSend\MailSendRateLimiter;
use App\Service\MailSend\SentFolder;
use App\Service\MailSend\SmtpTransportFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class MailSenderTest extends TestCase
{
    /**
     * Over HTTP the firewall stops a disabled user first, so this rule cannot be reached from there.
     */
    public function testADisabledUserGetsNoCredentialAndNoConnection(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');
        $user = new User();
        $user->setName('disabled');
        $user->setDomain($domain);
        $user->setEnabled(false);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');
        $transports = $this->createMock(SmtpTransportFactory::class);
        $transports->expects(self::never())->method('create');

        $credentials = new MailboxCredentials($connection, 5);
        $logger = new NullLogger();
        $sender = new MailSender(
            $credentials,
            $transports,
            new MailSendRateLimiter(new ArrayAdapter(), null, 1, 1, 3),
            new SentFolder(new ImapSessions($credentials, $this->createStub(ImapConnectionFactory::class), $logger), $logger),
            $logger,
        );

        $this->expectException(MailboxDeniedException::class);

        $sender->send($user, 1, new MailComposer(20, 1048576)->compose($user, ['bob@example.com'], [], [], 'Hello', 'Hello Bob'));
    }
}
