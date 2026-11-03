<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\MailboxListHeader;

/**
 * A mail that passed MailComposer, ready for MailSender.
 */
final readonly class OutgoingMail
{
    /**
     * @param list<string> $recipients everyone who gets it, blind copies included
     */
    public function __construct(
        public Email $email,
        public string $messageId,
        public array $recipients,
    ) {
    }

    /**
     * What goes in the Sent mailbox: the sent message, plus the Bcc header that Symfony removes for
     * the recipients and that the sender wants to see.
     */
    public function sentCopy(): string
    {
        $bcc = $this->email->getBcc();
        $message = $this->email->toString();

        if ([] === $bcc) {
            return $message;
        }

        return new MailboxListHeader('Bcc', $bcc)->toString() . "\r\n" . $message;
    }
}
