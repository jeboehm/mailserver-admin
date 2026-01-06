<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Controller\Security;

use App\Controller\Security\LogoutAction;
use PHPUnit\Framework\TestCase;

class LogoutActionTest extends TestCase
{
    private LogoutAction $subject;

    protected function setUp(): void
    {
        $this->subject = new LogoutAction();
    }

    public function testExceptionIsThrown(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Unexpected call.');

        $this->subject->__invoke();
    }
}
