<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use App\Entity\User;
use App\Exception\Mailbox\MailboxDeniedException;
use App\Exception\Mailbox\MailboxUnavailableException;
use App\Service\Mailbox\ImapSessions;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Keeps a copy of a sent mail in the user's Sent mailbox.
 *
 * By the time this runs the mail is gone, so nothing may look like a failed send from here on: a
 * caller would send it again. Whatever goes wrong comes back as a SentCopy.
 */
final readonly class SentFolder
{
    private const string DEFAULT_MAILBOX = 'Sent';

    public function __construct(
        private ImapSessions $sessions,
        private LoggerInterface $logger,
    ) {
    }

    public function save(User $user, string $message): SentCopy
    {
        try {
            return SentCopy::saved($this->sessions->run($user, function (ImapConnection $connection) use ($message): string {
                $mailbox = $this->pickMailbox($connection);
                $this->append($connection, $mailbox, $message);

                return $mailbox;
            }));
        } catch (MailboxDeniedException) {
            return SentCopy::skipped();
        } catch (MailboxUnavailableException $e) {
            return SentCopy::failed($e->retryable);
        } catch (\Throwable $e) {
            // Only the class: a stack trace would carry the message among the arguments.
            $this->logger->error('Saving the Sent copy failed with {class}.', ['class' => $e::class]);

            return SentCopy::failed(false);
        }
    }

    /**
     * Sent and Sent Messages can both carry \Sent. Sent wins, then the first by name, so it is always
     * the same one. Without any marked mailbox, Sent gets created.
     */
    private function pickMailbox(ImapConnection $connection): string
    {
        $marked = [];

        foreach ($connection->list() as $response) {
            $attributes = $response->tokenAt(2);
            $name = $response->tokenAt(4);

            if ($attributes instanceof ListData && $name instanceof Token && \in_array('\sent', array_map(strtolower(...), $attributes->values()), true)) {
                $marked[] = mb_scrub(Str::fromImapUtf7($name->value), 'UTF-8');
            }
        }

        sort($marked);

        return \in_array(self::DEFAULT_MAILBOX, $marked, true) || [] === $marked ? self::DEFAULT_MAILBOX : $marked[0];
    }

    private function append(ImapConnection $connection, string $mailbox, string $message): void
    {
        $encoded = Str::toImapUtf7($mailbox);

        try {
            $connection->append($encoded, $message, ['\Seen']);
        } catch (ImapCommandException $e) {
            if (!str_contains((string) $e->response(), '[TRYCREATE]')) {
                throw $e;
            }

            $this->create($connection, $encoded);
            $connection->append($encoded, $message, ['\Seen']);
        }
    }

    /**
     * A parallel send may have created it in the meantime, which is just as good.
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
