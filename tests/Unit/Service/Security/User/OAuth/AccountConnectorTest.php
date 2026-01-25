<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security\User\OAuth;

use App\Entity\User;
use App\Service\Security\User\OAuth\AccountConnector;
use Doctrine\ORM\EntityManagerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

class AccountConnectorTest extends TestCase
{
    private MockObject&EntityManagerInterface $entityManager;
    private AccountConnector $accountConnector;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->accountConnector = new AccountConnector($this->entityManager);
    }

    public function testConnectWithValidUser(): void
    {
        $user = new User();
        $response = $this->createStub(UserResponseInterface::class);

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($user);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $this->accountConnector->connect($user, $response);
    }

    public function testConnectThrowsExceptionWhenUserIsNotInstanceOfUser(): void
    {
        $user = $this->createStub(UserInterface::class);
        $response = $this->createStub(UserResponseInterface::class);

        $this->entityManager
            ->expects($this->never())
            ->method('persist');

        $this->entityManager
            ->expects($this->never())
            ->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('User must be an instance of %s', User::class));

        $this->accountConnector->connect($user, $response);
    }
}
