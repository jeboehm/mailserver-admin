<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Factory;

use App\Entity\Domain;
use App\Entity\User;
use App\Exception\DomainNotFoundException;
use App\Factory\UserFactory;
use App\Repository\DomainRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserFactoryTest extends TestCase
{
    private MockObject&DomainRepository $domainRepository;

    protected function setUp(): void
    {
        $this->domainRepository = $this->createMock(DomainRepository::class);
    }

    public function testCreateFromEmailAddressSuccessfullyCreatesUser(): void
    {
        $emailAddress = 'user@example.com';
        $domain = $this->createDomain('example.com');

        $this->domainRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'example.com'])
            ->willReturn($domain);

        $factory = $this->createFactory();
        $user = $factory->createFromEmailAddress($emailAddress);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('user', $user->getName());
        $this->assertEquals($domain, $user->getDomain());
    }

    public function testCreateFromEmailAddressConvertsEmailToLowercase(): void
    {
        $emailAddress = 'USER@EXAMPLE.COM';
        $domain = $this->createDomain('example.com');

        $this->domainRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'example.com'])
            ->willReturn($domain);

        $factory = $this->createFactory();
        $user = $factory->createFromEmailAddress($emailAddress);

        $this->assertEquals('user', $user->getName());
        $this->assertEquals($domain, $user->getDomain());
    }

    public function testCreateFromEmailAddressWithMixedCaseEmail(): void
    {
        $emailAddress = 'John.Doe@Example.COM';
        $domain = $this->createDomain('example.com');

        $this->domainRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'example.com'])
            ->willReturn($domain);

        $factory = $this->createFactory();
        $user = $factory->createFromEmailAddress($emailAddress);

        $this->assertEquals('john.doe', $user->getName());
        $this->assertEquals($domain, $user->getDomain());
    }

    #[DataProvider('invalidEmailProvider')]
    public function testCreateFromEmailAddressThrowsExceptionForInvalidEmail(string $invalidEmail): void
    {
        $this->domainRepository
            ->expects($this->never())
            ->method('findOneBy');

        $factory = $this->createFactory();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('"%s" is not a valid email address.', $invalidEmail));

        $factory->createFromEmailAddress($invalidEmail);
    }

    public static function invalidEmailProvider(): array
    {
        return [
            'empty string' => [''],
            'no @ symbol' => ['notanemail'],
            'no domain' => ['user@'],
            'no user' => ['@example.com'],
            'multiple @ symbols' => ['user@@example.com'],
            'invalid characters' => ['user@exam ple.com'],
            'no TLD' => ['user@example'],
            'spaces' => ['user @example.com'],
        ];
    }

    public function testCreateFromEmailAddressThrowsDomainNotFoundExceptionWhenDomainNotFound(): void
    {
        $emailAddress = 'user@nonexistent.com';

        $this->domainRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'nonexistent.com'])
            ->willReturn(null);

        $factory = $this->createFactory();

        $this->expectException(DomainNotFoundException::class);
        $this->expectExceptionMessage('Domain "nonexistent.com" not found');

        $factory->createFromEmailAddress($emailAddress);
    }

    public function testCreateFromEmailAddressWithComplexEmailAddress(): void
    {
        $emailAddress = 'user.name+tag@subdomain.example.com';
        $domain = $this->createDomain('subdomain.example.com');

        $this->domainRepository
            ->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'subdomain.example.com'])
            ->willReturn($domain);

        $factory = $this->createFactory();
        $user = $factory->createFromEmailAddress($emailAddress);

        $this->assertEquals('user.name+tag', $user->getName());
        $this->assertEquals($domain, $user->getDomain());
    }

    private function createFactory(): UserFactory
    {
        return new UserFactory($this->domainRepository);
    }

    private function createDomain(string $name): Domain
    {
        $domain = new Domain();
        $domain->setName($name);

        return $domain;
    }
}
