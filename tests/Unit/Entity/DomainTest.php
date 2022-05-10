<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Unit\Entity;

use App\Entity\Domain;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    public function testStringCast(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $this->assertEquals('example.com', (string) $domain);
    }
}
