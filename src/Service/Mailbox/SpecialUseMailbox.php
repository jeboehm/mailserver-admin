<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Support\Str;

/**
 * Picks the mailbox that carries a special-use attribute (Sent, Drafts, ...) and appends a message to it.
 */
final readonly class SpecialUseMailbox
{
    /**
     * Several mailboxes can carry the same attribute. The default name wins, then the first by name, so
     * it is always the same one. Without any marked mailbox, the default gets created.
     *
     * @param string       $attribute lower case special-use attribute, such as "\sent"
     * @param list<string> $flags     IMAP flags of the appended message, such as "\Seen"
     *
     * @return string the name of the mailbox the message went into
     */
    public function append(ImapConnection $connection, string $attribute, string $defaultName, string $message, array $flags): string
    {
        $mailbox = $this->pick($connection, $attribute, $defaultName);
        $encoded = Str::toImapUtf7($mailbox);

        try {
            $connection->append($encoded, $message, $flags);
        } catch (ImapCommandException $e) {
            if (!str_contains((string) $e->response(), '[TRYCREATE]')) {
                throw $e;
            }

            $this->create($connection, $encoded);
            $connection->append($encoded, $message, $flags);
        }

        return $mailbox;
    }

    private function pick(ImapConnection $connection, string $attribute, string $defaultName): string
    {
        $marked = [];

        foreach (ListedMailboxes::of($connection) as $name => $attributes) {
            if (\in_array($attribute, array_map(strtolower(...), $attributes), true)) {
                $marked[] = (string) $name;
            }
        }

        sort($marked);

        return \in_array($defaultName, $marked, true) || [] === $marked ? $defaultName : $marked[0];
    }

    /**
     * A parallel call may have created it in the meantime, which is just as good.
     */
    private function create(ImapConnection $connection, string $encodedMailbox): void
    {
        try {
            $connection->create($encodedMailbox);
        } catch (ImapCommandException $e) {
            if (!str_contains((string) $e->response(), '[ALREADYEXISTS]')) {
                throw $e;
            }
        }
    }
}
