<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\FetchmailAccount;

class RedisKeys
{
    public static function createRuntimeKey(int $accountId): string
    {
        return 'fetchmail_accounts_runtime_' . $accountId;
    }

    public static function createRunningKey(int $accountId): string
    {
        return 'fetchmail_accounts_running_' . $accountId;
    }

    public static function createAccountsKey(): string
    {
        return 'fetchmail_accounts';
    }
}
