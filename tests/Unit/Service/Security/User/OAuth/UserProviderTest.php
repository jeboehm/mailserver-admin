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
use App\Repository\UserRepository;
use App\Service\Security\User\OAuth\UserProvider;
use Doctrine\ORM\EntityManagerInterface;
use HWI\Bundle\OAuthBundle\OAuth\Response\PathUserResponse;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;

class UserProviderTest extends TestCase
{
    private MockObject&UserRepository $userRepository;
    private MockObject&EntityManagerInterface $entityManager;
    private UserProvider $subject;
    private string $adminGroupName = 'admin';

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->subject = new UserProvider($this->userRepository, $this->entityManager, $this->adminGroupName);
    }

    public static function dataProviderForTestSupportsClass(): array
    {
        return [
            ['random', false],
            [User::class, true],
        ];
    }

    public static function dataProviderForTestLoadUserByIdentifier(): array
    {
        return [
            ['random', false],
            ['Random', true],
        ];
    }

    public static function dataProviderForTestLoadUserByOAuthUserResponse(): array
    {
        return [
            ['user@example.com', true],
            ['user@example.com', false],
            ['invalid-email', false],
        ];
    }

    #[DataProvider('dataProviderForTestLoadUserByIdentifier')]
    public function testLoadUserByIdentifier(string $identifier, bool $expected): void
    {
        if (!$expected) {
            $this->expectException(AccountNotLinkedException::class);
            $this->expectExceptionMessage(\sprintf("User '%s' not found.", $identifier));
        }

        $user = $expected ? $this->createStub(User::class) : null;
        $this->userRepository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with($this->equalTo(strtolower($identifier)))
            ->willReturn($user);

        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->subject->loadUserByIdentifier($identifier);

        $this->assertSame($user, $result);
    }

    #[DataProvider('dataProviderForTestSupportsClass')]
    public function testSupportsClass(string $className, bool $result): void
    {
        $this->userRepository->expects($this->never())->method('findOneByEmailAddress');
        $this->entityManager->expects($this->never())->method('flush');

        if ($result) {
            $this->assertTrue($this->subject->supportsClass($className));
        } else {
            $this->assertFalse($this->subject->supportsClass($className));
        }
    }

    #[DataProvider('dataProviderForTestLoadUserByOAuthUserResponse')]
    public function testLoadUserByOAuthUserResponse(string $emailAddress, bool $userFound): void
    {
        $response = $this->createMock(PathUserResponse::class);
        $response->method('getUserIdentifier')->willReturn($emailAddress);
        $response->expects($this->never())->method('getUsername');
        $isValidEmail = false !== filter_var($emailAddress, \FILTER_VALIDATE_EMAIL);

        if (!$isValidEmail) {
            $this->expectException(AccountNotLinkedException::class);
            $this->expectExceptionMessage('No email address found in OAuth response. Check your OAUTH_PATHS_IDENTIFIER setting.');
            $response->expects($this->never())->method('getData');
        } else {
            $response->method('getData')->willReturn(['groups' => []]);
        }

        if (!$isValidEmail) {
            // Exception already set above
        } elseif (!$userFound) {
            $this->expectException(AccountNotLinkedException::class);
            $this->expectExceptionMessage(\sprintf("User '%s' not found.", $emailAddress));
        }

        $user = $userFound && $isValidEmail ? $this->createMock(User::class) : null;
        if ($user) {
            $user->expects($this->once())
                ->method('setAdmin')
                ->with($this->isBool());
            $user->expects($this->once())
                ->method('setEnabled')
                ->with(true);
            // User is initially admin and disabled, so both admin status and enabled status will change (triggering flush)
            $user->method('isAdmin')->willReturn(true);
            $user->method('getEnabled')->willReturn(false);
            $this->entityManager
                ->expects($this->once())
                ->method('flush');
        } else {
            $this->entityManager
                ->expects($this->never())
                ->method('flush');
        }

        $this->userRepository
            ->expects($isValidEmail ? $this->once() : $this->never())
            ->method('findOneByEmailAddress')
            ->with($this->equalTo(mb_strtolower($emailAddress)))
            ->willReturn($user);

        if ($isValidEmail && $userFound) {
            $result = $this->subject->loadUserByOAuthUserResponse($response);
            $this->assertSame($user, $result);
        } else {
            $this->subject->loadUserByOAuthUserResponse($response);
        }
    }

    public function testRefreshUserUnsupportedClass(): void
    {
        $user = $this->createStub(UserInterface::class);
        $this->expectException(UnsupportedUserException::class);
        $this->expectExceptionMessage(\sprintf('Instances of "%s" are not supported.', $user::class));
        $this->userRepository->expects($this->never())->method('findOneByEmailAddress');
        $this->entityManager->expects($this->never())->method('flush');

        $this->subject->refreshUser($user);
    }

    public function testRefreshUser(): void
    {
        $emailAddress = 'user@example.com';
        $user = $this->createMock(User::class);
        $user
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($emailAddress);

        $user->expects($this->never())->method('setAdmin');
        $user->expects($this->never())->method('setEnabled');

        $this->userRepository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with($this->equalTo(mb_strtolower($emailAddress)))
            ->willReturn($user);

        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->subject->refreshUser($user);
        $this->assertSame($user, $result);
    }

    public function testRefreshUserNotFound(): void
    {
        $emailAddress = 'user@example.com';

        $user = $this->createMock(User::class);
        $user
            ->expects($this->once())
            ->method('getUserIdentifier')
            ->willReturn($emailAddress);

        $user->expects($this->never())->method('setAdmin');
        $user->expects($this->never())->method('setEnabled');

        $this->expectException(AccountNotLinkedException::class);
        $this->expectExceptionMessage(\sprintf('User with ID "%s" could not be reloaded.', $emailAddress));

        $this->userRepository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with($this->equalTo(mb_strtolower($emailAddress)))
            ->willReturn(null);

        $this->entityManager->expects($this->never())->method('flush');

        $this->subject->refreshUser($user);
    }

    #[DataProvider('adminGroupProvider')]
    public function testLoadUserByOAuthUserResponseSetsAdminBasedOnGroups(
        array $data,
        bool $expectedAdmin,
        string $description
    ): void {
        $emailAddress = 'user@example.com';
        $response = $this->createStub(PathUserResponse::class);
        $response->method('getUserIdentifier')->willReturn($emailAddress);
        $response->method('getData')->willReturn($data);

        $user = $this->createMock(User::class);
        $user->expects($this->once())
            ->method('setAdmin')
            ->with($expectedAdmin);
        $user->expects($this->once())
            ->method('setEnabled')
            ->with(true);
        // Set up user initial state: opposite admin status (so it changes) and disabled (so it gets enabled)
        $user->method('isAdmin')->willReturn(!$expectedAdmin);
        $user->method('getEnabled')->willReturn(false);

        $this->userRepository
            ->expects($this->once())
            ->method('findOneByEmailAddress')
            ->with($this->equalTo(mb_strtolower($emailAddress)))
            ->willReturn($user);

        // Flush should be called because both admin status and enabled status change
        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $result = $this->subject->loadUserByOAuthUserResponse($response);
        $this->assertSame($user, $result, $description);
    }

    public static function adminGroupProvider(): array
    {
        return [
            'user in admin group' => [
                ['groups' => ['admin', 'other-group']],
                true,
                'Should set user as admin when in admin group',
            ],
            'user not in admin group' => [
                ['groups' => ['other-group']],
                false,
                'Should not set user as admin when not in admin group',
            ],
            'groups not array' => [
                ['groups' => false],
                false,
                'Should not set user as admin when groups is not array',
            ],
            'groups missing' => [
                [],
                false,
                'Should not set user as admin when groups is missing',
            ],
            'empty groups array' => [
                ['groups' => []],
                false,
                'Should not set user as admin when groups is empty',
            ],
        ];
    }
}
