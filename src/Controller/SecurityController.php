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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(private Security $security)
    {
    }

    #[Route(path: '/login', name: 'app_login')]
    public function loginAction(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->security->getUser()) {
            return $this->redirectToRoute('admin_index');
        }
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render(
            'admin/login.html.twig',
            [
                'last_username' => $lastUsername,
                'error' => $error,
                'target_path' => $this->generateUrl('admin_index'),
                'username_label' => 'Email address',
                'page_title' => '',
                'csrf_token_intention' => 'authenticate',
            ]
        );
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logoutAction(): Response
    {
        return $this->redirectToRoute('admin_index');
    }
}
