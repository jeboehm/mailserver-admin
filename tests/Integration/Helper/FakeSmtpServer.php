<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Helper;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;

/**
 * A submission server for tests: it answers like Postfix and remembers what the client sent.
 *
 * The test client reboots the kernel between requests, so a test reaches it through a static.
 * Without a current server, FakeSmtpTransportFactory connects for real.
 *
 * Replies are overridden per step: GREETING, EHLO, AUTH, MAIL, RCPT, DATA, END_OF_DATA. An empty
 * reply means the connection drops at that point.
 */
final class FakeSmtpServer extends AbstractStream
{
    private const array DEFAULT_REPLIES = [
        'GREETING' => '220 fake ESMTP',
        'EHLO' => "250-fake\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME",
        'AUTH' => '235 2.7.0 Authentication successful',
        'MAIL' => '250 2.1.0 Ok',
        'RCPT' => '250 2.1.5 Ok',
        'DATA' => '354 End data with <CR><LF>.<CR><LF>',
        'END_OF_DATA' => '250 2.0.0 Ok: queued as FAKEQUEUEID',
        'NOOP' => '250 2.0.0 Ok',
        'RSET' => '250 2.0.0 Ok',
        'QUIT' => '221 2.0.0 Bye',
    ];

    public static ?self $current = null;

    public int $connections = 0;

    /** @var list<string> the login of every AUTH attempt */
    public array $logins = [];

    /** @var list<string> */
    public array $mailFrom = [];

    /** @var list<string> */
    public array $rcptTo = [];

    /** The message as it went over the wire. */
    public string $data = '';

    public bool $quit = false;

    /** @var (\Closure(): void)|null */
    public ?\Closure $onAuth = null;

    /** @var list<string> */
    private array $pending = [];

    private bool $inData = false;

    /**
     * @param array<string, string> $replies
     */
    public function __construct(private readonly array $replies = [])
    {
    }

    public function setHost(string $host): static
    {
        return $this;
    }

    public function setPort(int $port): static
    {
        return $this;
    }

    public function disableTls(): static
    {
        return $this;
    }

    public function isTLS(): bool
    {
        return true;
    }

    #[\Override]
    public function initialize(): void
    {
        ++$this->connections;
        $this->reply('GREETING');
    }

    #[\Override]
    public function write(string $bytes, bool $debug = true): void
    {
        if ($this->inData) {
            if ("\r\n.\r\n" === $bytes) {
                $this->inData = false;
                $this->reply('END_OF_DATA');

                return;
            }

            $this->data .= $bytes;

            return;
        }

        $command = strtoupper(substr($bytes, 0, 4));

        match ($command) {
            'EHLO' => $this->reply('EHLO'),
            'AUTH' => $this->auth($bytes),
            'MAIL' => $this->mailFromCommand($bytes),
            'RCPT' => $this->rcptToCommand($bytes),
            'DATA' => $this->dataCommand(),
            'NOOP' => $this->reply('NOOP'),
            'RSET' => $this->reply('RSET'),
            'QUIT' => $this->quitCommand(),
            default => throw new \LogicException(\sprintf('Unexpected SMTP command "%s".', rtrim($bytes))),
        };
    }

    #[\Override]
    public function flush(): void
    {
    }

    #[\Override]
    public function terminate(): void
    {
    }

    #[\Override]
    public function readLine(): string
    {
        return array_shift($this->pending) ?? throw new TransportException('Connection to "fake" has been closed unexpectedly.');
    }

    #[\Override]
    protected function getReadConnectionDescription(): string
    {
        return 'fake';
    }

    private function reply(string $step): void
    {
        $reply = $this->replies[$step] ?? self::DEFAULT_REPLIES[$step];

        if ('' === $reply) {
            return;
        }

        foreach (explode("\r\n", $reply) as $line) {
            $this->pending[] = $line . "\r\n";
        }
    }

    private function auth(string $command): void
    {
        $plain = (string) base64_decode(trim(substr($command, \strlen('AUTH PLAIN '))), true);
        $this->logins[] = explode("\0", $plain)[1] ?? '';

        if (null !== $this->onAuth) {
            ($this->onAuth)();
        }

        $this->reply('AUTH');
    }

    private function mailFromCommand(string $command): void
    {
        $this->mailFrom[] = $this->addressOf($command);
        $this->reply('MAIL');
    }

    private function rcptToCommand(string $command): void
    {
        $this->rcptTo[] = $this->addressOf($command);
        $this->reply('RCPT');
    }

    private function addressOf(string $command): string
    {
        if (1 !== preg_match('/<([^>]*)>/', $command, $matches)) {
            throw new \LogicException(\sprintf('No address in SMTP command "%s".', rtrim($command)));
        }

        return $matches[1];
    }

    private function dataCommand(): void
    {
        $this->inData = true;
        $this->reply('DATA');
    }

    private function quitCommand(): void
    {
        $this->quit = true;
        $this->reply('QUIT');
    }
}
