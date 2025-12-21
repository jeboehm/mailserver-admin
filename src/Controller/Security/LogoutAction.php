<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

#[Route(path: '/logout', name: 'app_logout')]
readonly class LogoutAction
{
    public function __construct(
        private RouterInterface $router,
    ) {
    }

    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse(
            $this->router->generate(LoginAction::DEFAULT_ROUTE)
        );
    }
}
