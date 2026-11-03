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

    public const array ALL = [self::ManageRead, self::ManageWrite, self::MailRead, self::MailSend];

    /**
     * Writing and sending have to be a deliberate choice: together with mail:read, either lets a mail
     * the agent reads instruct it to act.
     */
    public const array PRESELECTED = [self::ManageRead, self::MailRead];

    public function description(): string
    {
        return match ($this) {
            self::ManageRead => 'Read domains, users, aliases, fetchmail accounts and DKIM status; run DNS validation',
            self::ManageWrite => 'Create, update and delete the same',
            self::MailRead => 'Read your own mailbox',
            self::MailSend => 'Send mail from your own address',
        };
    }
}
