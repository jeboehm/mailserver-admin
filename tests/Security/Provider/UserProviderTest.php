<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Security\Provider;

use App\Entity\Domain;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Provider\UserProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

class UserProviderTest extends TestCase
{
    /**
     * @var UserRepository|MockObject
     */
    private $repository;

    protected function setUp()
    {
        $this->repository = $this->getMockBuilder(UserRepository::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testSupportsClass(): void
    {
        $provider = new UserProvider($this->repository);
        $this->assertTrue($provider->supportsClass(User::class));
    }

    public function testLoadUserByUsername(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $user = new User();
        $user->setName('admin');
        $user->setPassword('test1234');
        $user->setDomain($domain);

        $this->repository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with('admin@example.com')
            ->willReturn($user);

        $provider = new UserProvider($this->repository);

        $this->assertSame($user, $provider->loadUserByUsername('admin@example.com'));
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\UsernameNotFoundException
     */
    public function testLoadUserByUsernameWithUnknownUser(): void
    {
        $this->repository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with('admin@example.com')
            ->willReturn(null);

        $provider = new UserProvider($this->repository);
        $provider->loadUserByUsername('admin@example.com');
    }

    public function testLoadUserByUsernameAdminGetsRole(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $user = new User();
        $user->setName('admin');
        $user->setPassword('test1234');
        $user->setDomain($domain);
        $user->setAdmin(true);

        $this->repository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with('admin@example.com')
            ->willReturn($user);

        $provider = new UserProvider($this->repository);
        $loadedUser = $provider->loadUserByUsername('admin@example.com');
        $this->assertContains('ROLE_ADMIN', $loadedUser->getRoles());
    }

    /**
     * @expectedException \Symfony\Component\Security\Core\Exception\UnsupportedUserException
     */
    public function testRefreshUserOnlyAcceptsUserClass(): void
    {
        $provider = new UserProvider($this->repository);
        $provider->refreshUser(new TestUser());
    }

    public function testRefreshUser(): void
    {
        $domain = new Domain();
        $domain->setName('example.com');

        $user = new User();
        $user->setName('admin');
        $user->setPassword('test1234');
        $user->setDomain($domain);

        $this->repository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with('admin@example.com')
            ->willReturn($user);

        $provider = new UserProvider($this->repository);

        $this->assertSame($user, $provider->refreshUser($user));
    }
}

class TestUser implements UserInterface
{
    public function getRoles()
    {
    }

    public function getPassword()
    {
    }

    public function getSalt()
    {
    }

    public function getUsername()
    {
    }

    public function eraseCredentials()
    {
    }
}
