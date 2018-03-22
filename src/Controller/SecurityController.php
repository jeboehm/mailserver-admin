<?php

namespace App\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController
{
    /**
     * @Route("/login", name="app_login")
     * @Template()
     */
    public function loginAction(AuthenticationUtils $authenticationUtils): array
    {
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return [
            'last_username' => $lastUsername,
            'error'         => $error !== null ? $error->getMessage() : '',
        ];
    }

    /**
     * @Route("/logout", name="app_logout")
     */
    public function logoutAction(): RedirectResponse
    {
        return new RedirectResponse('/');
    }
}
