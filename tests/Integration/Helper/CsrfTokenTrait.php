<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Helper;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

trait CsrfTokenTrait
{
    /**
     * Issues a real session-bound CSRF token by hooking into a GET request.
     *
     * EasyAdmin answers an invalid token with a redirect to the dashboard, which a
     * test could mistake for a rejection, so deletion tests need a valid token. Must
     * be the client's first request: the kernel reboots afterwards and drops the listener.
     */
    private function issueCsrfToken(KernelBrowser $client, string $url, string $tokenId): string
    {
        $container = $client->getContainer();
        $token = null;

        $container->get('event_dispatcher')->addListener(
            KernelEvents::CONTROLLER,
            static function () use ($container, $tokenId, &$token): void {
                $token ??= $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
            }
        );

        $client->request(Request::METHOD_GET, $url);

        \assert(\is_string($token));

        return $token;
    }
}
