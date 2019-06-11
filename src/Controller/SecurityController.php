<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
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
            'error' => null !== $error ? $error->getMessage() : '',
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
