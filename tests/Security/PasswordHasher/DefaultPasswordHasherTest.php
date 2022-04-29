<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Security\PasswordHasher;

use App\Security\PasswordHasher\DefaultPasswordHasher;
use PHPUnit\Framework\TestCase;

class DefaultPasswordHasherTest extends TestCase
{
    public function testEncodePassword(): void
    {
        $encoder = new DefaultPasswordHasher();

        $this->assertEquals(
            '$5$rounds=5000$foobar$joZHfrY.Gm7dk58W7QpTp5emRPtnOQqbv9p/MIFdJ2.',
            $encoder->encodePassword('test1234', 'foobar')
        );
    }

    public function testIsPasswordValid(): void
    {
        $encoder = new DefaultPasswordHasher();

        $this->assertTrue(
            $encoder->isPasswordValid(
                '$5$rounds=5000$foobar$joZHfrY.Gm7dk58W7QpTp5emRPtnOQqbv9p/MIFdJ2.',
                'test1234',
                'foobar'
            )
        );
    }

    public function testNeedsRehash(): void
    {
        $this->assertFalse((new DefaultPasswordHasher())->needsRehash('xy'));
    }
}
