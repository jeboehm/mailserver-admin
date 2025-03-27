<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Entity;

use App\Entity\Alias;
use App\Entity\Domain;
use PHPUnit\Framework\TestCase;

class AliasTest extends TestCase
{
    public function testStringCast(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $alias = new Alias();
        $alias->setDomain($domain);
        $alias->setName('jeff');
        $alias->setDestination('ext@example.com');

        $this->assertEquals('jeff@example.com → ext@example.com', (string) $alias);
    }

    public function testStringCastEmptyDomain(): void
    {
        $alias = new Alias();
        $alias->setName('jeff');
        $alias->setDestination('ext@example.com');

        $this->assertEquals('', (string) $alias);
    }
}
