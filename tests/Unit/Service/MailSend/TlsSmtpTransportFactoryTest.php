<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\MailSend;

use App\Service\MailboxCredential\ShortLivedCredential;
use App\Service\MailSend\TlsSmtpTransportFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

class TlsSmtpTransportFactoryTest extends TestCase
{
    public function testTheCredentialSurvivesTheDsnAndTheHostStaysTheHost(): void
    {
        $transport = new TlsSmtpTransportFactory('mta:2587', 25)->create(new ShortLivedCredential('cred#admin@example.com', 'se/cr#et@:x'));
        $stream = $transport->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('cred#admin@example.com', $transport->getUsername());
        self::assertSame('se/cr#et@:x', $transport->getPassword());
        self::assertSame('mta', $stream->getHost());
        self::assertSame(2587, $stream->getPort());
    }

    public function testThePortDefaultsToSubmission(): void
    {
        $stream = new TlsSmtpTransportFactory('mta', 25)->create(new ShortLivedCredential('cred#a@example.com', 'x'))->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(587, $stream->getPort());
    }

    public function testTlsIsRequiredWithoutVerifyingTheCertificate(): void
    {
        $transport = new TlsSmtpTransportFactory('mta:587', 25)->create(new ShortLivedCredential('cred#a@example.com', 'x'));
        $stream = $transport->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertTrue($transport->isTlsRequired());
        self::assertFalse($stream->getStreamOptions()['ssl']['verify_peer']);
        self::assertFalse($stream->getStreamOptions()['ssl']['verify_peer_name']);
    }

    public function testTheTimeoutOutlastsAnAuthPenalty(): void
    {
        $stream = new TlsSmtpTransportFactory('mta:587', 25)->create(new ShortLivedCredential('cred#a@example.com', 'x'))->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertEqualsWithDelta(25.0, $stream->getTimeout(), 0.001);
    }
}
