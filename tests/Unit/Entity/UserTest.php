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
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testEraseCredentials(): void
    {
        $user = new User();
        $user->setPlainPassword('LoremIpsum');
        $user->eraseCredentials();

        $this->assertEmpty($user->getPlainPassword());
    }

    public function testGetSalt(): void
    {
        $user = new User();
        $user->setPassword('$5$rounds=5000$dfee4ba3d4f76c11$OrSeU3xlQ7u58fVe.xExNwW6ImDPP2lrItyH1WhoGV/');

        $this->assertEquals('dfee4ba3d4f76c11', $user->getSalt());
    }

    public function testStringCast(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $user = new User();
        $user->setName('jeff');
        $user->setDomain($domain);

        $this->assertEquals('jeff@example.com', (string) $user);
    }

    public function testStringCastEmptyDomain(): void
    {
        $user = new User();
        $user->setName('jeff');
        $this->assertEquals('', (string) $user);
    }
}
