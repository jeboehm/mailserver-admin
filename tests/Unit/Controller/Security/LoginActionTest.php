<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Controller\Security;

use App\Controller\Security\LoginAction;
use App\Entity\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

class LoginActionTest extends TestCase
{
    private MockObject|Security $security;
    private MockObject|AuthenticationUtils $authenticationUtils;
    private MockObject|Environment $twig;
    private MockObject|RouterInterface $router;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->authenticationUtils = $this->createMock(AuthenticationUtils::class);
        $this->twig = $this->createMock(Environment::class);
        $this->router = $this->createMock(RouterInterface::class);
    }

    public function testRedirectsToAdminWhenUserIsLoggedIn(): void
    {
        $user = $this->createStub(User::class);
        $adminUrl = '/admin';

        $this->security->expects($this->once())
            ->method('getUser')
            ->willReturn($user);

        $this->router->expects($this->once())
            ->method('generate')
            ->with('admin')
            ->willReturn($adminUrl);

        $this->authenticationUtils->expects($this->never())
            ->method('getLastAuthenticationError');

        $this->authenticationUtils->expects($this->never())
            ->method('getLastUsername');

        $this->twig->expects($this->never())
            ->method('render');

        $controller = new LoginAction(
            security: $this->security,
            authenticationUtils: $this->authenticationUtils,
            twig: $this->twig,
            router: $this->router,
            oauthEnabled: false,
            oauthButtonText: '',
        );

        $response = $controller->__invoke();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($adminUrl, $response->getTargetUrl());
    }

    public function testRendersLoginPageWhenUserIsNotLoggedIn(): void
    {
        $lastUsername = 'user@example.com';
        $renderedContent = '<html>Login Page</html>';

        $this->security->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastAuthenticationError')
            ->willReturn(null);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastUsername')
            ->willReturn($lastUsername);

        $this->router->expects($this->never())
            ->method('generate');

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                '@EasyAdmin/page/login.html.twig',
                $this->callback(static function (array $context) use ($lastUsername) {
                    return '<h1>mailserver-admin</h1>' === $context['page_title']
                        && $lastUsername === $context['last_username']
                        && null === $context['error']
                        && 'Email address' === $context['username_label']
                        && false === $context['enable_oauth']
                        && '' === $context['oauth_button_text']
                        && 'authenticate' === $context['csrf_token_intention'];
                })
            )
            ->willReturn($renderedContent);

        $controller = new LoginAction(
            security: $this->security,
            authenticationUtils: $this->authenticationUtils,
            twig: $this->twig,
            router: $this->router,
            oauthEnabled: false,
            oauthButtonText: '',
        );

        $response = $controller->__invoke();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame($renderedContent, $response->getContent());
    }

    public function testRendersLoginPageWithAuthenticationError(): void
    {
        $lastUsername = 'user@example.com';
        $error = new AuthenticationException('Invalid credentials');
        $renderedContent = '<html>Login Page</html>';

        $this->security->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastAuthenticationError')
            ->willReturn($error);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastUsername')
            ->willReturn($lastUsername);

        $this->router->expects($this->never())
            ->method('generate');

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                '@EasyAdmin/page/login.html.twig',
                $this->callback(static function (array $context) use ($lastUsername, $error) {
                    return '<h1>mailserver-admin</h1>' === $context['page_title']
                        && $lastUsername === $context['last_username']
                        && $error === $context['error']
                        && 'Email address' === $context['username_label']
                        && false === $context['enable_oauth']
                        && '' === $context['oauth_button_text']
                        && 'authenticate' === $context['csrf_token_intention'];
                })
            )
            ->willReturn($renderedContent);

        $controller = new LoginAction(
            security: $this->security,
            authenticationUtils: $this->authenticationUtils,
            twig: $this->twig,
            router: $this->router,
            oauthEnabled: false,
            oauthButtonText: '',
        );

        $response = $controller->__invoke();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame($renderedContent, $response->getContent());
    }

    public function testRendersLoginPageWithOAuthEnabled(): void
    {
        $lastUsername = '';
        $oauthButtonText = 'Sign in with OAuth';
        $renderedContent = '<html>Login Page</html>';

        $this->security->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastAuthenticationError')
            ->willReturn(null);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastUsername')
            ->willReturn($lastUsername);

        $this->router->expects($this->never())
            ->method('generate');

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                '@EasyAdmin/page/login.html.twig',
                $this->callback(static function (array $context) use ($lastUsername, $oauthButtonText) {
                    return '<h1>mailserver-admin</h1>' === $context['page_title']
                        && $lastUsername === $context['last_username']
                        && null === $context['error']
                        && 'Email address' === $context['username_label']
                        && true === $context['enable_oauth']
                        && $oauthButtonText === $context['oauth_button_text']
                        && 'authenticate' === $context['csrf_token_intention'];
                })
            )
            ->willReturn($renderedContent);

        $controller = new LoginAction(
            security: $this->security,
            authenticationUtils: $this->authenticationUtils,
            twig: $this->twig,
            router: $this->router,
            oauthEnabled: true,
            oauthButtonText: $oauthButtonText,
        );

        $response = $controller->__invoke();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame($renderedContent, $response->getContent());
    }

    public function testRendersLoginPageWithoutLastUsername(): void
    {
        $lastUsername = '';
        $renderedContent = '<html>Login Page</html>';

        $this->security->expects($this->once())
            ->method('getUser')
            ->willReturn(null);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastAuthenticationError')
            ->willReturn(null);

        $this->authenticationUtils->expects($this->once())
            ->method('getLastUsername')
            ->willReturn($lastUsername);

        $this->router->expects($this->never())
            ->method('generate');

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                '@EasyAdmin/page/login.html.twig',
                $this->callback(static function (array $context) use ($lastUsername) {
                    return '<h1>mailserver-admin</h1>' === $context['page_title']
                        && $lastUsername === $context['last_username']
                        && null === $context['error']
                        && 'Email address' === $context['username_label']
                        && false === $context['enable_oauth']
                        && '' === $context['oauth_button_text']
                        && 'authenticate' === $context['csrf_token_intention'];
                })
            )
            ->willReturn($renderedContent);

        $controller = new LoginAction(
            security: $this->security,
            authenticationUtils: $this->authenticationUtils,
            twig: $this->twig,
            router: $this->router,
            oauthEnabled: false,
            oauthButtonText: '',
        );

        $response = $controller->__invoke();

        self::assertInstanceOf(Response::class, $response);
        self::assertSame($renderedContent, $response->getContent());
    }
}
