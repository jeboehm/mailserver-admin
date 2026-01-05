<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Entity;

use App\Entity\Domain;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
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

    public function testSerialize(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $user = new User();
        $user->setName('jeff');
        $user->setPassword('hashed_password');
        $user->setDomain($domain);
        $user->setAdmin(true);
        $user->setDomainAdmin(false);

        // Use reflection to set the id since it's private and auto-generated
        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setAccessible(true);
        $idProperty->setValue($user, 123);

        $serialized = $user->__serialize();

        $this->assertIsArray($serialized);
        $this->assertCount(6, $serialized);
        $this->assertEquals(123, $serialized[0]);
        $this->assertEquals('hashed_password', $serialized[1]);
        $this->assertEquals('example.com', $serialized[2]);
        $this->assertTrue($serialized[3]);
        $this->assertFalse($serialized[4]);
        $this->assertEquals('jeff', $serialized[5]);
    }

    public function testUnserialize(): void
    {
        $user = new User();
        $data = [456, 'hashed_password_123', 'test.com', false, true, 'alice'];

        $user->__unserialize($data);

        $this->assertEquals(456, $user->getId());
        $this->assertEquals('hashed_password_123', $user->getPassword());
        $this->assertEquals('alice', $user->getName());
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->isDomainAdmin());

        // Verify domainName is set (used in __toString when domain is null)
        $reflection = new \ReflectionClass($user);
        $domainNameProperty = $reflection->getProperty('domainName');
        $domainNameProperty->setAccessible(true);
        $this->assertEquals('test.com', $domainNameProperty->getValue($user));
    }
}
