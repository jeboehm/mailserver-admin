<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\Security;

use App\Service\Security\OAuthStaticUser;
use App\Service\Security\OAuthUserProvider;
use App\Service\Security\Roles;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Exception\SessionUnavailableException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

class OAuthUserProviderTest extends TestCase
{
    private MockObject|RequestStack $requestStack;
    private MockObject|SessionInterface $session;
    private OAuthUserProvider $provider;

    protected function setUp(): void
    {
        $this->requestStack = $this->createStub(RequestStack::class);
        $this->session = $this->createStub(SessionInterface::class);

        // Default to enabled=true, adminGroup='admins'
        $this->provider = new OAuthUserProvider(true, 'admins', $this->requestStack);
    }

    public function testRefreshUserThrowsWhenDisabled(): void
    {
        $provider = new OAuthUserProvider(false, 'admins', $this->requestStack);
        $user = new OAuthStaticUser('test', false);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('OAuth not enabled');

        $provider->refreshUser($user);
    }

    public function testRefreshUserReturnsUser(): void
    {
        $user = new OAuthStaticUser('test', false);
        $refreshed = $this->provider->refreshUser($user);

        $this->assertSame($user, $refreshed);
    }

    public function testSupportsClass(): void
    {
        $this->assertTrue($this->provider->supportsClass(OAuthStaticUser::class));
        $this->assertFalse($this->provider->supportsClass(UserInterface::class));
    }

    public function testLoadUserByIdentifierThrowsWhenDisabled(): void
    {
        $provider = new OAuthUserProvider(false, 'admins', $this->requestStack);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('OAuth not enabled');

        $provider->loadUserByIdentifier('test');
    }

    public function testLoadUserByIdentifierThrowsWhenSessionUnavailable(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->expectException(SessionUnavailableException::class);

        $this->provider->loadUserByIdentifier('test');
    }

    public function testLoadUserByIdentifierThrowsWhenUserNotInSession(): void
    {
        $this->setupSession();
        $this->session->method('has')->with('oauth_user_provider')->willReturn(false);

        $this->expectException(UserNotFoundException::class);

        $this->provider->loadUserByIdentifier('test');
    }

    public function testLoadUserByIdentifierThrowsWhenSessionHasDifferentUser(): void
    {
        $this->setupSession();
        $this->session->method('has')->with('oauth_user_provider')->willReturn(true);
        $this->session->method('get')->with('oauth_user_provider')->willReturn(new OAuthStaticUser('other', false));

        $this->expectException(UserNotFoundException::class);

        $this->provider->loadUserByIdentifier('test');
    }

    public function testLoadUserByIdentifierReturnsUser(): void
    {
        $this->setupSession();
        $user = new OAuthStaticUser('test', true);

        $this->session->method('has')->with('oauth_user_provider')->willReturn(true);
        $this->session->method('get')->with('oauth_user_provider')->willReturn($user);

        $loadedUser = $this->provider->loadUserByIdentifier('test');

        $this->assertSame($user, $loadedUser);
    }

    public function testLoadUserByOAuthUserResponseThrowsWhenDisabled(): void
    {
        $provider = new OAuthUserProvider(false, 'admins', $this->requestStack);
        $response = $this->createStub(UserResponseInterface::class);

        $this->expectException(UserNotFoundException::class);
        $this->expectExceptionMessage('OAuth not enabled');

        $provider->loadUserByOAuthUserResponse($response);
    }

    public function testLoadUserByOAuthUserResponseCreatesAdminUserWhenGroupMatches(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
        $request = $this->createStub(Request::class);
        $request->method('getSession')->willReturn($this->session);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $response = $this->createStub(UserResponseInterface::class);
        $response->method('getNickname')->willReturn('testuser');
        $response->method('getData')->willReturn(['groups' => ['users', 'admins']]);

        $this->session->expects($this->once())->method('set')->with(
            'oauth_user_provider',
            $this->callback(fn (OAuthStaticUser $user) => 'testuser' === $user->getUserIdentifier() && in_array(Roles::ROLE_ADMIN, $user->getRoles()))
        );

        $user = $this->provider->loadUserByOAuthUserResponse($response);

        $this->assertEquals('testuser', $user->getUserIdentifier());
        $this->assertContains(Roles::ROLE_ADMIN, $user->getRoles());
    }

    public function testLoadUserByOAuthUserResponseCreatesNormalUserWhenGroupMismatch(): void
    {
        $this->setupSession();

        $response = $this->createStub(UserResponseInterface::class);
        $response->method('getNickname')->willReturn('testuser');
        $response->method('getData')->willReturn(['groups' => ['users']]);

        $user = $this->provider->loadUserByOAuthUserResponse($response);

        $this->assertNotContains(Roles::ROLE_ADMIN, $user->getRoles());
    }

    public function testLoadUserByOAuthUserResponseCreatesAdminWhenNoGroupConfigured(): void
    {
        $this->setupSession();
        // Provider with empty admin group -> everyone is admin
        $provider = new OAuthUserProvider(true, '', $this->requestStack);

        $response = $this->createStub(UserResponseInterface::class);
        $response->method('getNickname')->willReturn('testuser');
        $response->method('getData')->willReturn([]);

        $user = $provider->loadUserByOAuthUserResponse($response);

        $this->assertContains(Roles::ROLE_ADMIN, $user->getRoles());
    }

    public function testLoadUserByOAuthUserResponseUsesUserIdentifierIfNicknameEmpty(): void
    {
        $this->setupSession();

        $response = $this->createStub(UserResponseWithIdentifier::class);

        $response->method('getNickname')->willReturn('');
        $response->method('getUserIdentifier')->willReturn('user@example.com');
        $response->method('getData')->willReturn([]);

        $user = $this->provider->loadUserByOAuthUserResponse($response);

        $this->assertEquals('user@example.com', $user->getUserIdentifier());
    }

    private function setupSession(): void
    {
        $request = $this->createStub(Request::class);
        $request->method('getSession')->willReturn($this->session);
        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }
}

interface UserResponseWithIdentifier extends UserResponseInterface
{
    public function getUserIdentifier();
}
