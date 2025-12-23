<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service;

use App\Entity\User;
use App\Service\PasswordService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

class PasswordServiceTest extends TestCase
{
    private MockObject|PasswordHasherFactoryInterface $passwordHasherFactory;

    private PasswordService $passwordService;

    private MockObject|PasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->passwordHasherFactory = $this->createMock(PasswordHasherFactoryInterface::class);
        $this->passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $this->passwordService = new PasswordService($this->passwordHasherFactory);
    }

    public function testProcessUserPassword(): void
    {
        $user = new User();

        $this->passwordHasher
            ->expects($this->once())
            ->method('hash')
            ->with('test4321')
            ->willReturn('test1234');
        $this->passwordHasherFactory
            ->expects($this->once())
            ->method('getPasswordHasher')
            ->with($user)
            ->willReturn($this->passwordHasher);

        $user->setPlainPassword('test4321');

        $this->passwordService->processUserPassword($user);
        $this->assertEquals('test1234', $user->getPassword());
    }

    public function testProcessNullPassword(): void
    {
        $user = new User();
        $user->setPlainPassword(null);

        $this->passwordHasher
            ->expects($this->never())
            ->method('hash');
        $this->passwordHasherFactory
            ->expects($this->never())
            ->method('getPasswordHasher');

        $this->passwordService->processUserPassword($user);

        $this->assertEmpty($user->getPassword());
    }

    public function testProcessEmptyPassword(): void
    {
        $user = new User();
        $user->setPlainPassword('');

        $this->passwordHasher
            ->expects($this->never())
            ->method('hash');
        $this->passwordHasherFactory
            ->expects($this->never())
            ->method('getPasswordHasher');

        $this->passwordService->processUserPassword($user);

        $this->assertEmpty($user->getPassword());
    }
}
