<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Mailbox;

use App\Entity\User;
use App\Exception\Mailbox\MailboxDeniedException;
use App\Exception\Mailbox\MailboxUnavailableException;
use App\Exception\MailboxCredential\TooManyImapSessionsException;
use App\Service\MailboxCredential\MailboxCredentials;
use App\Service\MailboxCredential\MailboxProtocol;
use App\Service\MailboxCredential\ShortLivedCredential;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\Exception as ImapException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\Exceptions\ImapResponseException;
use DirectoryTree\ImapEngine\Exceptions\ImapStreamException;
use Psr\Log\LoggerInterface;

/**
 * One IMAP session as a given user, for the length of one call. Nothing here takes a user from
 * anywhere but the caller: pass the authenticated user and nothing else.
 *
 * The operation gets the connection only. Use its low-level commands, as ImapEngine's fluent API
 * sends SELECT and its bodyPart() defaults to a fetch that sets \Seen. Open mailboxes with examine()
 * and fetch with BODY.PEEK unless the operation is meant to change the mailbox.
 */
final readonly class ImapSessions
{
    /** Server answers that go away by themselves: connection limits, auth trouble, a busy mailbox. */
    public const string RETRYABLE_RESPONSE = '/\[(LIMIT|UNAVAILABLE|INUSE|SERVERBUG)\]/';

    public function __construct(
        private MailboxCredentials $credentials,
        private ImapConnectionFactory $connections,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(ImapConnection): T $operation
     *
     * @throws MailboxDeniedException      when the user has no IMAP access
     * @throws MailboxUnavailableException when the session failed, without any detail of the server's answer
     *
     * @return T
     */
    public function run(User $user, callable $operation): mixed
    {
        // The passdb refuses both as well. Checked here too, so no credential is ever issued for them.
        if (!$user->getEnabled() || $user->getSendOnly()) {
            throw new MailboxDeniedException();
        }

        try {
            return $this->credentials->withCredential(
                $user,
                MailboxProtocol::Imap,
                fn (ShortLivedCredential $credential): mixed => $this->session($credential, $operation),
            );
        } catch (TooManyImapSessionsException) {
            throw MailboxUnavailableException::retryable();
        } catch (ImapException $e) {
            // Only the message: a stack trace would carry the secret among the arguments of login().
            $this->logger->warning('IMAP session failed: {message}', ['message' => $e->getMessage()]);

            throw $this->isRetryable($e) ? MailboxUnavailableException::retryable() : MailboxUnavailableException::failed();
        }
    }

    /**
     * @template T
     *
     * @param callable(ImapConnection): T $operation
     *
     * @return T
     */
    private function session(ShortLivedCredential $credential, callable $operation): mixed
    {
        $connection = $this->connections->connect();

        try {
            $connection->login($credential->login, $credential->secret);

            return $operation($connection);
        } finally {
            $this->close($connection);
        }
    }

    /**
     * A failed goodbye is no reason to fail a call that got what it wanted.
     */
    private function close(ImapConnection $connection): void
    {
        try {
            $connection->logout();
        } catch (ImapException) {
        } finally {
            $connection->disconnect();
        }
    }

    private function isRetryable(ImapException $e): bool
    {
        return match (true) {
            $e instanceof ImapCommandException => 1 === preg_match(self::RETRYABLE_RESPONSE, $e->getMessage()),
            $e instanceof ImapConnectionException, $e instanceof ImapStreamException, $e instanceof ImapResponseException => true,
            default => false,
        };
    }
}
