<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\Security\User\OAuth;

use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\HttpUtils;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The failure_path of the oauth firewall is the registration route, which HWIOAuthBundle can only generate for an
 * unlinked account, because only then it has a registration key. Every other failure goes back to the login page.
 * The bundle ignores the failure_handler option, so this decorates the handler it creates for the firewall.
 */
#[AsDecorator('security.authentication.failure_handler.main.oauth')]
readonly class AuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        #[AutowireDecorated]
        private AuthenticationFailureHandlerInterface $registrationHandler,
        private HttpUtils $httpUtils,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception->getPrevious() instanceof AccountNotLinkedException) {
            return $this->registrationHandler->onAuthenticationFailure($request, $exception);
        }

        // Not the original exception: its message and token stay out of the session and the login page.
        $request->getSession()->set(
            SecurityRequestAttributes::AUTHENTICATION_ERROR,
            new CustomUserMessageAuthenticationException('OAuth login failed. Please try again.'),
        );

        return $this->httpUtils->createRedirectResponse($request, 'app_login');
    }
}
