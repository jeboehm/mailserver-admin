<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailDraft;

use App\Entity\User;
use App\Exception\Mailbox\MailboxDeniedException;
use App\Exception\Mailbox\MailboxUnavailableException;
use App\Exception\MailDraft\MailDraftFailedException;
use App\Exception\MailDraft\MailDraftRateLimitedException;
use App\Service\Mailbox\ImapSessions;
use App\Service\Mailbox\SpecialUseMailbox;
use App\Service\MailSend\OutgoingMail;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use Psr\Log\LoggerInterface;

/**
 * Saves a mail as a draft in the user's Drafts mailbox and sends nothing: pass the authenticated user
 * and nothing else.
 *
 * Unlike the Sent copy, a failure here is a failure: nothing has happened yet, so every problem is an
 * exception and there is no "skipped". Do not call this inside a transaction: Dovecot checks the
 * credential on login.
 */
final readonly class MailDrafter
{
    private const string DEFAULT_MAILBOX = 'Drafts';

    public function __construct(
        private ImapSessions $sessions,
        private MailDraftRateLimiter $rateLimiter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MailboxDeniedException        when the user has no IMAP access
     * @throws MailDraftRateLimitedException when the limit is reached, before anything was tried
     * @throws MailDraftFailedException      when the draft was not saved
     *
     * @return string the name of the mailbox the draft went into
     */
    public function save(User $user, int $tokenId, OutgoingMail $mail): string
    {
        // The passdb refuses these as well. Checked first, so a refused call costs no part of the limit.
        if (!$user->getEnabled() || $user->getSendOnly()) {
            throw new MailboxDeniedException();
        }

        $this->rateLimiter->consume($tokenId);

        $message = $mail->sentCopy();

        try {
            return $this->sessions->run($user, static function (ImapConnection $connection) use ($message): string {
                return new SpecialUseMailbox()->append($connection, '\\drafts', self::DEFAULT_MAILBOX, $message, ['\\Draft', '\\Seen']);
            });
        } catch (MailboxDeniedException $e) {
            throw $e;
        } catch (MailboxUnavailableException $e) {
            throw $e->retryable ? MailDraftFailedException::retryable() : MailDraftFailedException::failed();
        } catch (\Throwable $e) {
            // Only the class: a stack trace would carry the message among the arguments.
            $this->logger->error('Saving the draft failed with {class}.', ['class' => $e::class]);

            throw MailDraftFailedException::failed();
        }
    }
}
