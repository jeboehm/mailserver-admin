<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\FetchmailAccount;

use App\Service\FetchmailAccount\RuntimeData;
use PHPUnit\Framework\TestCase;

class RuntimeDataTest extends TestCase
{
    public function testRuntimeDataProperties(): void
    {
        $runtimeData = new RuntimeData();

        $now = new \DateTimeImmutable();
        $runtimeData->isSuccess = true;
        $runtimeData->lastRun = $now;
        $runtimeData->lastLog = 'Success log';

        $this->assertTrue($runtimeData->isSuccess);
        $this->assertSame($now, $runtimeData->lastRun);
        $this->assertEquals('Success log', $runtimeData->lastLog);
    }
}
