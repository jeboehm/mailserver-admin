<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Helper;

/**
 * The public output of the mail tools, as the JSON they return.
 */
trait MailToolOutputTrait
{
    /**
     * @param array<string, mixed> $output
     *
     * @return array{total: int, mailboxes: list<array{name: string, attributes: list<string>, messages: int|null, unseen: int|null}>}
     */
    private function asMailboxList(array $output): array
    {
        /** @var array{total: int, mailboxes: list<array{name: string, attributes: list<string>, messages: int|null, unseen: int|null}>} $typed */
        $typed = $output;

        return $typed;
    }

    /**
     * @param array<string, mixed> $output
     *
     * @return array{notice: string, mailbox: string, total: int, messages: list<array{uid: int, flags: list<string>, size: int, received_at: string|null, untrusted_external_content: array{from: string|null, to: string|null, cc: string|null, reply_to: string|null, subject: string|null, date: string|null, message_id: string|null}}>}
     */
    private function asMessageSearch(array $output): array
    {
        /** @var array{notice: string, mailbox: string, total: int, messages: list<array{uid: int, flags: list<string>, size: int, received_at: string|null, untrusted_external_content: array{from: string|null, to: string|null, cc: string|null, reply_to: string|null, subject: string|null, date: string|null, message_id: string|null}}>} $typed */
        $typed = $output;

        return $typed;
    }

    /**
     * @param array<string, mixed> $output
     *
     * @return array{notice: string, mailbox: string, uid: int, flags: list<string>, size: int, received_at: string|null, body_truncated: bool, body_converted_from_html: bool, untrusted_external_content: array{from: string|null, to: string|null, cc: string|null, reply_to: string|null, subject: string|null, date: string|null, message_id: string|null, body: string, attachments: list<array{filename: string|null, content_type: string, size: int}>}}
     */
    private function asMessage(array $output): array
    {
        /** @var array{notice: string, mailbox: string, uid: int, flags: list<string>, size: int, received_at: string|null, body_truncated: bool, body_converted_from_html: bool, untrusted_external_content: array{from: string|null, to: string|null, cc: string|null, reply_to: string|null, subject: string|null, date: string|null, message_id: string|null, body: string, attachments: list<array{filename: string|null, content_type: string, size: int}>}} $typed */
        $typed = $output;

        return $typed;
    }

    /**
     * @param array<string, mixed> $output
     *
     * @return array{sent: bool, message_id: string, from: string, recipients: list<string>, sent_copy: array{status: 'saved', mailbox: string}|array{status: 'skipped', reason: string}|array{status: 'failed', temporary: bool, reason: string}}
     */
    private function asSentMail(array $output): array
    {
        /** @var array{sent: bool, message_id: string, from: string, recipients: list<string>, sent_copy: array{status: 'saved', mailbox: string}|array{status: 'skipped', reason: string}|array{status: 'failed', temporary: bool, reason: string}} $typed */
        $typed = $output;

        return $typed;
    }
}
