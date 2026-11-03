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
use App\Exception\MailSend\MailSendFailedException;
use App\Exception\MailSend\MailSendRateLimitedException;
use App\Service\MailboxCredential\MailboxCredentials;
use App\Service\MailboxCredential\MailboxProtocol;
use App\Service\MailboxCredential\ShortLivedCredential;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * Sends a mail as the given user: pass the authenticated user and nothing else.
 *
 * The user gets the mail through Postfix with an SMTP credential, then a copy in Sent with a separate
 * IMAP credential. Do not call this inside a transaction: Dovecot checks the credentials on login.
 */
final readonly class MailSender
{
    public function __construct(
        private MailboxCredentials $credentials,
        private SmtpTransportFactoryInterface $transports,
        private MailSendRateLimiter $rateLimiter,
        private SentFolder $sentFolder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws MailboxDeniedException       when the user may not send
     * @throws MailSendRateLimitedException when a limit is reached, before anything was tried
     * @throws MailSendFailedException      when the mail was not sent, or may not have been
     */
    public function send(User $user, int $tokenId, OutgoingMail $mail): SentCopy
    {
        // The passdb refuses this as well. Checked here too, so no credential is ever issued for it.
        if (!$user->getEnabled()) {
            throw new MailboxDeniedException();
        }

        $this->rateLimiter->consume($tokenId, (int) $user->getId());
        $this->submit($user, $mail);

        return $this->sentFolder->save($user, $mail->sentCopy());
    }

    private function submit(User $user, OutgoingMail $mail): void
    {
        $sent = false;

        try {
            $this->credentials->withCredential(
                $user,
                MailboxProtocol::Smtp,
                function (ShortLivedCredential $credential) use ($mail, &$sent): void {
                    $this->deliver($this->transports->create($credential), $mail);
                    $sent = true;
                },
            );
        } catch (\Throwable $e) {
            if (!$sent) {
                throw $e;
            }

            // The mail is gone and the credential expires by itself. A failed send would be a lie.
            $this->logger->error('Deleting the SMTP credential failed with {class}.', ['class' => $e::class]);
        }
    }

    /**
     * Connecting and logging in come first, separately: a connection lost there cannot have sent
     * anything, while one lost later may have, so a retry could deliver the mail twice.
     */
    private function deliver(EsmtpTransport $transport, OutgoingMail $mail): void
    {
        try {
            try {
                $transport->start();
            } catch (TransportExceptionInterface $e) {
                $this->logFailure($e);

                throw $this->isPermanent($e) ? MailSendFailedException::refused($e->getCode()) : MailSendFailedException::retryable();
            }

            try {
                $transport->send($mail->email);
            } catch (TransportExceptionInterface $e) {
                $this->logFailure($e);

                throw match (true) {
                    0 === $e->getCode() => MailSendFailedException::outcomeUnknown(),
                    $this->isPermanent($e) => MailSendFailedException::refused($e->getCode()),
                    default => MailSendFailedException::retryable(),
                };
            }
        } finally {
            $transport->stop();
        }
    }

    /**
     * Anything but 4xx from the server or no answer at all, which go away by themselves.
     */
    private function isPermanent(TransportExceptionInterface $e): bool
    {
        return 0 !== $e->getCode() && ($e->getCode() < 400 || $e->getCode() >= 500);
    }

    /**
     * Only the message: getDebug() and a stack trace would carry the credential.
     */
    private function logFailure(TransportExceptionInterface $e): void
    {
        $this->logger->warning('Submission failed: {message}', ['message' => $e->getMessage()]);
    }
}
