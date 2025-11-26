<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security;

use App\Service\Security\Roles;
use PHPUnit\Framework\TestCase;

class RolesTest extends TestCase
{
    public function testConstants(): void
    {
        $this->assertSame('ROLE_USER', Roles::ROLE_USER);
        $this->assertSame('ROLE_ADMIN', Roles::ROLE_ADMIN);
        $this->assertSame('ROLE_DOMAIN_ADMIN', Roles::ROLE_DOMAIN_ADMIN);
    }
}
