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
use App\Service\Mailbox\SpecialUseMailbox;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
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
            return SentCopy::saved($this->sessions->run($user, static function (ImapConnection $connection) use ($message): string {
                return new SpecialUseMailbox()->append($connection, '\\sent', self::DEFAULT_MAILBOX, $message, ['\\Seen']);
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
}
