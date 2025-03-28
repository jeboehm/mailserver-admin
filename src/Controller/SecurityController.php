<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    private const string DEFAULT_ROUTE = 'admin_index';

    public function __construct(private readonly Security $security)
    {
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->security->getUser()) {
            return $this->redirectToRoute(self::DEFAULT_ROUTE);
        }
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render(
            '@EasyAdmin/page/login.html.twig',
            [
                'page_title' => '<h1>mailserver-admin</h1>',
                'target_path' => self::DEFAULT_ROUTE,
                'last_username' => $lastUsername,
                'error' => $error,
                'username_label' => 'Email address',
                'enable_oauth' => (bool) $this->getParameter('app_oauth_enabled'),
                'oauth_button_text' => $this->getParameter('app_oauth_button_text'),
            ]
        );
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): Response
    {
        return $this->redirectToRoute(self::DEFAULT_ROUTE);
    }
}
