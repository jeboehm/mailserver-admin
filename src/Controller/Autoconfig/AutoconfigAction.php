<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Autoconfig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

#[Route(path: '/.well-known/autoconfig/mail/config-v1.1.xml', methods: [Request::METHOD_GET])]
readonly class AutoconfigAction
{
    public function __construct(
        private Environment $twig,
        #[Autowire('%env(default::string:MAILNAME)%')]
        private ?string $mailname,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $mailname = $this->mailname ?? $request->getHost();

        return new Response(
            content: $this->twig->render('admin/autoconfig/autoconfig.xml.twig', ['mailname' => $mailname]),
            headers: [
                'Content-Type' => 'application/xml; charset=utf-8',
            ]
        );
    }
}
