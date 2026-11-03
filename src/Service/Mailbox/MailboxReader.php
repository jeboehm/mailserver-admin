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
use App\Service\Mailbox\DTO\MailboxSummary;
use App\Service\Mailbox\DTO\MessageAttachment;
use App\Service\Mailbox\DTO\MessageContent;
use App\Service\Mailbox\DTO\MessageCriteria;
use App\Service\Mailbox\DTO\MessageEnvelope;
use App\Service\Mailbox\DTO\MessageSearchPage;
use DirectoryTree\ImapEngine\BodyStructureCollection;
use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\EmailAddress;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads a user's own mailbox. Every method is one IMAP session, and none of them changes anything:
 * mailboxes are opened with EXAMINE and everything is fetched with BODY.PEEK.
 */
final readonly class MailboxReader
{
    private const string HEADER_FIELDS = 'FROM TO CC REPLY-TO SUBJECT DATE MESSAGE-ID';

    /** Enough for the fields above on any sensible message. It bounds memory on the others. */
    private const int MAX_HEADER_BYTES = 65536;

    public function __construct(
        private ImapSessions $sessions,
        private BodyDecoder $decoder,
        private HtmlToText $htmlToText,
        #[Autowire('%env(int:MCP_MAIL_MESSAGE_MAX_BYTES)%')]
        private int $messageMaxBytes,
    ) {
    }

    /**
     * @return array{total: int, mailboxes: list<MailboxSummary>} INBOX first, then by name
     */
    public function listMailboxes(User $user, int $limit, int $offset): array
    {
        return $this->sessions->run($user, function (ImapConnection $connection) use ($limit, $offset): array {
            $listed = ListedMailboxes::of($connection);

            uksort($listed, static fn (string $a, string $b): int => ['INBOX' !== $a, $a] <=> ['INBOX' !== $b, $b]);

            $mailboxes = [];

            foreach (\array_slice($listed, $offset, $limit, true) as $name => $attributes) {
                $mailboxes[] = $this->summarise($connection, (string) $name, $attributes);
            }

            return ['total' => \count($listed), 'mailboxes' => $mailboxes];
        });
    }

    public function search(User $user, string $mailbox, MessageCriteria $criteria, int $limit, int $offset): MessageSearchPage
    {
        return $this->sessions->run($user, function (ImapConnection $connection) use ($mailbox, $criteria, $limit, $offset): MessageSearchPage {
            $this->open($connection, $mailbox);

            $uids = [];

            foreach ($connection->search($criteria->toSearchArguments())->tokensAfter(2) as $token) {
                if ($token instanceof Token) {
                    $uids[] = (int) $token->value;
                }
            }

            rsort($uids);

            $page = \array_slice($uids, $offset, $limit);

            if ([] === $page) {
                return new MessageSearchPage(\count($uids), []);
            }

            $envelopes = [];

            foreach ($connection->fetch($this->envelopeItems(), $page) as $response) {
                if (!$response instanceof UntaggedResponse) {
                    continue;
                }

                $envelope = $this->envelope($this->fetchData($response));
                $envelopes[$envelope->uid] = $envelope;
            }

            krsort($envelopes);

            return new MessageSearchPage(\count($uids), array_values($envelopes));
        });
    }

    /**
     * @throws MailboxDeniedException when there is no such mailbox or message
     */
    public function get(User $user, string $mailbox, int $uid): MessageContent
    {
        return $this->sessions->run($user, function (ImapConnection $connection) use ($mailbox, $uid): MessageContent {
            $this->open($connection, $mailbox);

            $response = $connection->fetch([...$this->envelopeItems(), 'BODYSTRUCTURE'], $uid)->first();

            if (!$response instanceof UntaggedResponse) {
                throw new MailboxDeniedException();
            }

            $data = $this->fetchData($response);
            $parts = $this->parts($data);
            $body = $this->bodyPart($parts);

            [$text, $truncated] = null === $body ? ['', false] : $this->readBody($connection, $uid, $body);

            return new MessageContent(
                $this->envelope($data),
                $text,
                $truncated,
                null !== $body && $body->isHtml(),
                $this->attachments($parts, $body),
            );
        });
    }

    private function open(ImapConnection $connection, string $mailbox): void
    {
        try {
            $connection->examine(Str::toImapUtf7($mailbox));
        } catch (ImapCommandException $e) {
            if (ImapSessions::isRetryableAnswer($e)) {
                throw $e;
            }

            throw new MailboxDeniedException(previous: $e);
        }
    }

    /**
     * @param list<string> $attributes
     */
    private function summarise(ImapConnection $connection, string $name, array $attributes): MailboxSummary
    {
        if (\in_array('\Noselect', $attributes, true) || \in_array('\NonExistent', $attributes, true)) {
            return new MailboxSummary($name, $attributes, null, null);
        }

        $counters = $connection->status(Str::toImapUtf7($name), ['MESSAGES', 'UNSEEN'])->tokenAt(3);

        if (!$counters instanceof ListData) {
            return new MailboxSummary($name, $attributes, null, null);
        }

        return new MailboxSummary(
            $name,
            $attributes,
            $this->intValue($counters, 'MESSAGES'),
            $this->intValue($counters, 'UNSEEN'),
        );
    }

    /**
     * @return list<string>
     */
    private function envelopeItems(): array
    {
        return [
            'UID',
            'FLAGS',
            'RFC822.SIZE',
            'INTERNALDATE',
            \sprintf('BODY.PEEK[HEADER.FIELDS (%s)]<0.%d>', self::HEADER_FIELDS, self::MAX_HEADER_BYTES),
        ];
    }

    private function fetchData(UntaggedResponse $response): ListData
    {
        $data = $response->tokenAt(3);

        if (!$data instanceof ListData) {
            throw new MailboxDeniedException();
        }

        return $data;
    }

    private function envelope(ListData $data): MessageEnvelope
    {
        $flags = $data->lookup('FLAGS');
        $receivedAt = $data->lookup('INTERNALDATE');

        return new MessageEnvelope(
            $this->intValue($data, 'UID'),
            $flags instanceof ListData ? array_values(array_filter($flags->values(), 'is_string')) : [],
            $this->intValue($data, 'RFC822.SIZE'),
            $receivedAt instanceof Token ? $this->isoDate($receivedAt->value) : null,
            $this->headers($this->sectionValue($data, '[HEADER.FIELDS') ?? ''),
        );
    }

    private function intValue(ListData $data, string $field): int
    {
        $token = $data->lookup($field);

        return $token instanceof Token ? (int) $token->value : 0;
    }

    private function isoDate(string $internalDate): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('d-M-Y H:i:s O', trim($internalDate));

        return false === $date ? null : $date->format(\DATE_ATOM);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $raw): array
    {
        $headers = [];

        foreach ((array) preg_split('/\r?\n(?![ \t])/', trim($raw)) as $line) {
            $pair = explode(':', (string) $line, 2);

            if (2 === \count($pair)) {
                $headers[strtolower(trim($pair[0]))] = trim($this->decoder->header($pair[1]));
            }
        }

        return $headers;
    }

    /**
     * The value of a body section in a FETCH response: "BODY", the section in brackets, optionally
     * the "<0>" of a partial fetch, then the content.
     */
    private function sectionValue(ListData $data, string $sectionPrefix): ?string
    {
        $tokens = $data->tokens();

        foreach ($tokens as $index => $token) {
            if (!$token instanceof ResponseCodeData || !str_starts_with((string) $token, $sectionPrefix)) {
                continue;
            }

            $value = $tokens[$index + 1] ?? null;
            $value = $value instanceof EmailAddress ? ($tokens[$index + 2] ?? null) : $value;

            return $value instanceof Token ? $value->value : null;
        }

        return null;
    }

    /**
     * @return list<BodyStructurePart>
     */
    private function parts(ListData $data): array
    {
        $structure = $data->lookup('BODYSTRUCTURE');

        if (!$structure instanceof ListData || [] === $structure->tokens()) {
            return [];
        }

        return $structure->first() instanceof ListData
            ? array_values(BodyStructureCollection::fromListData($structure)->flatten())
            : [BodyStructurePart::fromListData($structure)];
    }

    /**
     * The first plain text part, otherwise the first HTML part.
     *
     * @param list<BodyStructurePart> $parts
     */
    private function bodyPart(array $parts): ?BodyStructurePart
    {
        $readable = array_filter($parts, static fn (BodyStructurePart $part): bool => !$part->isAttachment());

        foreach ($readable as $part) {
            if ($part->isText()) {
                return $part;
            }
        }

        foreach ($readable as $part) {
            if ($part->isHtml()) {
                return $part;
            }
        }

        return null;
    }

    /**
     * Fetches no more than the limit can use, as fetching a large message whole takes PHP's memory
     * with it. An encoded part needs more raw bytes to give the same amount of text.
     *
     * @return array{string, bool} the text and whether it was cut
     */
    private function readBody(ImapConnection $connection, int $uid, BodyStructurePart $part): array
    {
        $fetchBytes = match (strtolower((string) $part->encoding())) {
            'quoted-printable' => $this->messageMaxBytes * 3,
            'base64' => (int) ceil($this->messageMaxBytes * 4 / 3) + 4,
            default => $this->messageMaxBytes,
        };

        $response = $connection->fetch(
            ['UID', \sprintf('BODY.PEEK[%s]<0.%d>', $part->partNumber(), $fetchBytes)],
            $uid,
        )->first();

        $raw = $response instanceof UntaggedResponse
            ? $this->sectionValue($this->fetchData($response), \sprintf('[%s]', $part->partNumber())) ?? ''
            : '';

        $text = $this->decoder->decode($raw, $part->encoding(), $part->charset());
        $text = $part->isHtml() ? $this->htmlToText->convert($text) : $text;

        $truncated = \strlen($raw) >= $fetchBytes && ($part->size() ?? 0) > $fetchBytes;

        if (\strlen($text) <= $this->messageMaxBytes) {
            return [$text, $truncated];
        }

        return [mb_strcut($text, 0, $this->messageMaxBytes, 'UTF-8'), true];
    }

    /**
     * @param list<BodyStructurePart> $parts
     *
     * @return list<MessageAttachment>
     */
    private function attachments(array $parts, ?BodyStructurePart $body): array
    {
        $attachments = [];

        foreach ($parts as $part) {
            if ($part === $body || (!$part->isAttachment() && ($part->isText() || $part->isHtml()))) {
                continue;
            }

            $filename = $part->filename();

            $attachments[] = new MessageAttachment(
                null === $filename ? null : $this->decoder->header($filename),
                $this->decoder->header($part->contentType()),
                $part->size(),
            );
        }

        return $attachments;
    }
}
