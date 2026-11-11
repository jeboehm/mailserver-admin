<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\ApiToken;

/**
 * What a token may be used for. A tool call also needs the owner's current role to allow it.
 */
enum ApiTokenScope: string
{
    case ManageRead = 'manage:read';
    case ManageWrite = 'manage:write';
    case MailRead = 'mail:read';
    case MailSend = 'mail:send';
    case MailDraft = 'mail:draft';

    public const array ALL = [self::ManageRead, self::ManageWrite, self::MailRead, self::MailSend, self::MailDraft];

    /**
     * Writing and sending have to be a deliberate choice: together with mail:read, either lets a mail
     * the agent reads instruct it to act.
     */
    public const array PRESELECTED = [self::ManageRead, self::MailRead];

    /** The scopes whose mail goes out under the sender mode of the token. */
    public const array SENDER_BOUND = [self::MailSend, self::MailDraft];

    /**
     * Whether mail goes out under the sender mode of the token: only mail:send and mail:draft write
     * mail as someone.
     */
    public function isSenderBound(): bool
    {
        return \in_array($this, self::SENDER_BOUND, true);
    }

    /**
     * @param iterable<mixed> $scopes anything else than a scope counts as not sender bound
     */
    public static function anySenderBound(iterable $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($scope instanceof self && $scope->isSenderBound()) {
                return true;
            }
        }

        return false;
    }

    public function description(): string
    {
        return match ($this) {
            self::ManageRead => 'Read domains, users, aliases, fetchmail accounts and DKIM status; run DNS validation',
            self::ManageWrite => 'Create, update and delete the same',
            self::MailRead => 'Read your own mailbox',
            self::MailSend => 'Send mail from your own address',
            self::MailDraft => 'Mail drafts: save a draft in your Drafts mailbox, never sends',
        };
    }
}
