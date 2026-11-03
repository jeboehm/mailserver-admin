<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Helper;

use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;

/**
 * An IMAP server for tests: it plays back a script and remembers what the client sent.
 *
 * The test client reboots the kernel between requests, so a test reaches it through a static.
 * Without a current server, FakeImapConnectionFactory connects for real.
 */
final class FakeImapServer extends FakeStream
{
    public static ?self $current = null;

    public int $connections = 0;

    /** @var list<string> */
    public array $commands = [];

    /** @var (\Closure(): void)|null */
    public ?\Closure $onLogin = null;

    /**
     * @param list<string> $script the lines the server sends, starting after the greeting
     */
    public function __construct(array $script)
    {
        $this->feed(['* OK ready', ...$script]);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function open(?string $transport = null, ?string $host = null, ?int $port = null, ?int $timeout = null, array $options = []): bool
    {
        ++$this->connections;

        return parent::open($transport, $host, $port, $timeout, $options);
    }

    #[\Override]
    public function fwrite(string $data): int|false
    {
        $this->commands[] = rtrim($data, "\r\n");

        if (str_contains($data, ' LOGIN ') && null !== $this->onLogin) {
            ($this->onLogin)();
        }

        return parent::fwrite($data);
    }

    #[\Override]
    public function close(): void
    {
        // Keeps the rest of the script for assertions: the client disconnects at the end of a call.
        $this->connection = null;
    }

    public function sent(): string
    {
        return implode("\n", $this->commands);
    }
}
