<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

#[Route(path: '/login', name: 'app_login')]
readonly class LoginAction
{
    public function __construct(
        private Security $security,
        private AuthenticationUtils $authenticationUtils,
        private Environment $twig,
        private RouterInterface $router,
        #[Autowire('%env(bool:OAUTH_ENABLED)%')]
        private bool $oauthEnabled,
        #[Autowire('%env(string:OAUTH_BUTTON_TEXT)%')]
        private string $oauthButtonText,
    ) {
    }

    public function __invoke(): Response
    {
        if (null !== $this->security->getUser()) {
            return new RedirectResponse($this->router->generate('admin'));
        }

        $error = $this->authenticationUtils->getLastAuthenticationError();
        $lastUsername = $this->authenticationUtils->getLastUsername();

        return new Response(
            $this->twig->render(
                '@EasyAdmin/page/login.html.twig',
                [
                    'page_title' => '<h1>mailserver-admin</h1>',
                    'last_username' => $lastUsername,
                    'error' => $error,
                    'username_label' => 'Email address',
                    'enable_oauth' => $this->oauthEnabled,
                    'oauth_button_text' => $this->oauthButtonText,
                    'csrf_token_intention' => 'authenticate',
                ]
            )
        );
    }
}
