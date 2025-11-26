<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\FetchmailAccount;

use App\Service\FetchmailAccount\RedisKeys;
use PHPUnit\Framework\TestCase;

class RedisKeysTest extends TestCase
{
    public function testCreateRuntimeKey(): void
    {
        $this->assertEquals('fetchmail_accounts_runtime_123', RedisKeys::createRuntimeKey(123));
    }

    public function testCreateRunningKey(): void
    {
        $this->assertEquals('fetchmail_accounts_running_456', RedisKeys::createRunningKey(456));
    }

    public function testCreateAccountsKey(): void
    {
        $this->assertEquals('fetchmail_accounts', RedisKeys::createAccountsKey());
    }
}
