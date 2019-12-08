<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Security\Encoder;

use App\Security\Encoder\DefaultPasswordEncoder;
use PHPUnit\Framework\TestCase;

class DefaultPasswordEncoderTest extends TestCase
{
    public function testEncodePassword()
    {
        $encoder = new DefaultPasswordEncoder();

        $this->assertEquals(
            '$5$rounds=5000$foobar$joZHfrY.Gm7dk58W7QpTp5emRPtnOQqbv9p/MIFdJ2.',
            $encoder->encodePassword('test1234', 'foobar')
        );
    }

    public function testIsPasswordValid()
    {
        $encoder = new DefaultPasswordEncoder();

        $this->assertTrue(
            $encoder->isPasswordValid(
                '$5$rounds=5000$foobar$joZHfrY.Gm7dk58W7QpTp5emRPtnOQqbv9p/MIFdJ2.',
                'test1234',
                'foobar'
            )
        );
    }

    public function testNeedsRehash()
    {
        $this->assertFalse((new DefaultPasswordEncoder())->needsRehash('xy'));
    }
}
