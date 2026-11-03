<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailboxCredential;

use App\Entity\MailboxCredential;
use App\Entity\User;
use App\Exception\MailboxCredential\TooManyImapSessionsException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lends an operation a login for one protocol of one user's mailbox.
 *
 * Passwords are only stored hashed and there is no master user, so mailserver-admin logs in with
 * credentials of its own. Dovecot checks them on login, so every statement here must be committed
 * when it returns: do not call this inside a transaction.
 */
final readonly class MailboxCredentials
{
    private const string LOGIN_PREFIX = 'cred#';

    public function __construct(
        private Connection $connection,
        #[Autowire('%env(int:MCP_MAIL_MAX_IMAP_SESSIONS)%')]
        private int $maxImapSessions,
    ) {
    }

    /**
     * Runs the operation with a new credential, which is gone afterwards whatever the outcome. A
     * crash in between leaves an expired row, which no longer logs in and is purged here later.
     *
     * For IMAP, this throws TooManyImapSessionsException before the operation has run. Whatever the
     * operation throws reaches the caller.
     *
     * @template T
     *
     * @param callable(ShortLivedCredential): T $operation login straight away, as the credential only lives for
     *                                                     about a minute and auth penalties eat into that; do not
     *                                                     retry with the same one
     *
     * @return T
     */
    public function withCredential(User $user, MailboxProtocol $protocol, callable $operation): mixed
    {
        $this->purgeExpired();

        $secret = bin2hex(random_bytes(32));
        $secretHash = hash('sha256', $secret);

        $this->store($user, $protocol, $secretHash);

        try {
            if (MailboxProtocol::Imap === $protocol && $this->liveImapCredentials($user) > $this->maxImapSessions) {
                throw new TooManyImapSessionsException(\sprintf('%s has too many IMAP sessions.', $user));
            }

            return $operation(new ShortLivedCredential(self::LOGIN_PREFIX . $user, $secret));
        } finally {
            $this->connection->delete(MailboxCredential::TABLE, ['secret_hash' => $secretHash]);
        }
    }

    /**
     * The expiry comes from the database clock, because that is what Dovecot compares it with. A
     * timezone mismatch between PHP and the database would move it.
     */
    private function store(User $user, MailboxProtocol $protocol, string $secretHash): void
    {
        $this->connection->executeStatement(
            \sprintf(
                "INSERT INTO %s (user_id, secret_hash, kind, allow_imap, allow_smtp, expires_at, created_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP + INTERVAL '60' SECOND, CURRENT_TIMESTAMP)",
                MailboxCredential::TABLE,
            ),
            [
                $user->getId(),
                $secretHash,
                MailboxCredentialKind::ShortLived->value,
                MailboxProtocol::Imap === $protocol,
                MailboxProtocol::Smtp === $protocol,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::STRING,
                ParameterType::STRING,
                ParameterType::BOOLEAN,
                ParameterType::BOOLEAN,
            ],
        );
    }

    /**
     * Counted after our own row is in place, so parallel callers cannot all slip through. At worst
     * they all see each other and all retry, which is the outcome they are told to expect anyway.
     */
    private function liveImapCredentials(User $user): int
    {
        $count = $this->connection->fetchOne(
            \sprintf(
                'SELECT COUNT(*) FROM %s WHERE user_id = ? AND kind = ? AND allow_imap = ? AND expires_at > CURRENT_TIMESTAMP',
                MailboxCredential::TABLE,
            ),
            [$user->getId(), MailboxCredentialKind::ShortLived->value, true],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::BOOLEAN],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    private function purgeExpired(): void
    {
        $this->connection->executeStatement(
            \sprintf('DELETE FROM %s WHERE kind = ? AND expires_at <= CURRENT_TIMESTAMP', MailboxCredential::TABLE),
            [MailboxCredentialKind::ShortLived->value],
        );
    }
}
