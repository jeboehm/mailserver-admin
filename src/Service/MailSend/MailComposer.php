<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MailSend;

use App\Exception\MailSend\InvalidMailException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\InvalidArgumentException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Turns what a caller wants to send into a mail from the given sender address, or refuses it. The
 * caller decides which address that is; the Message-ID is marked with its domain. Nothing here
 * connects anywhere, so a refusal costs no part of a send limit.
 */
final readonly class MailComposer
{
    private const string X_MAILER = 'mailserver-admin (https://github.com/jeboehm/mailserver-admin)';

    public function __construct(
        #[Autowire('%env(int:MCP_MAIL_SEND_MAX_RECIPIENTS)%')]
        private int $maxRecipients,
        #[Autowire('%env(int:MCP_MAIL_SEND_MAX_BYTES)%')]
        private int $maxBytes,
    ) {
    }

    /**
     * @param string       $sender the From address, which is also the envelope sender
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $bcc
     *
     * @throws InvalidMailException
     */
    public function compose(string $sender, array $to, array $cc, array $bcc, string $subject, string $body): OutgoingMail
    {
        $toAddresses = $this->addresses($to);
        $ccAddresses = $this->addresses($cc);
        $bccAddresses = $this->addresses($bcc);

        $recipients = array_values(array_unique(array_map(
            static fn (Address $address): string => $address->getAddress(),
            [...$toAddresses, ...$ccAddresses, ...$bccAddresses],
        )));

        if ([] === $toAddresses) {
            throw new InvalidMailException('The mail needs at least one recipient in "to".');
        }

        if (\count($recipients) > $this->maxRecipients) {
            throw new InvalidMailException(\sprintf('A mail can have at most %d recipients.', $this->maxRecipients));
        }

        if ('' === trim($subject) || 1 === preg_match('/[\x00-\x1F\x7F]/', $subject)) {
            throw new InvalidMailException('The subject must not be empty or contain control characters.');
        }

        if ('' === trim($body)) {
            throw new InvalidMailException('The body must not be empty.');
        }

        $from = new Address($sender);
        $messageId = \sprintf('%s@%s', bin2hex(random_bytes(16)), substr((string) strrchr($from->getAddress(), '@'), 1));

        // Message-ID and Date are set here, so the sent message and the Sent copy cannot differ.
        $email = new Email()
            ->from($from)
            ->to(...$toAddresses)
            ->cc(...$ccAddresses)
            ->bcc(...$bccAddresses)
            ->subject($subject)
            ->date(new \DateTimeImmutable())
            ->text($body);
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);
        // A fixed value: nothing about the instance, the token or the caller belongs in it.
        $email->getHeaders()->addTextHeader('X-Mailer', self::X_MAILER);

        if (\strlen($email->toString()) > $this->maxBytes) {
            throw new InvalidMailException(\sprintf('The message is larger than the limit of %d bytes.', $this->maxBytes));
        }

        return new OutgoingMail($email, \sprintf('<%s>', $messageId), $recipients);
    }

    /**
     * Plain addresses only: they end up in SMTP commands and headers, so the validation must see all
     * of the value. A non-ASCII local part needs SMTPUTF8 from every server on the way, so it is left
     * out; a non-ASCII domain is fine, as it goes out encoded.
     *
     * @param list<string> $addresses
     *
     * @return list<Address>
     */
    private function addresses(array $addresses): array
    {
        try {
            $parsed = array_map(static fn (string $address): Address => new Address($address), $addresses);
        } catch (InvalidArgumentException|RfcComplianceException) {
            throw new InvalidMailException('Every recipient must be a plain address such as name@example.com.');
        }

        foreach ($parsed as $address) {
            if (1 === preg_match('/[^\x00-\x7F]/', (string) strstr($address->getAddress(), '@', true))) {
                throw new InvalidMailException('Addresses with non-ASCII characters before the @ are not supported.');
            }
        }

        return $parsed;
    }
}
