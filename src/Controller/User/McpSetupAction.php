<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\User;

use App\Service\ApiToken\ApiTokenScope;
use App\Service\McpSetup\McpClientGuides;
use App\Service\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[AdminRoute('/mcp-setup', name: 'mcp_setup')]
#[IsGranted(Roles::ROLE_USER)]
final readonly class McpSetupAction
{
    private const string ENDPOINT_ROUTE = '_mcp_endpoint_default';

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(
        private Environment $twig,
        private UrlGeneratorInterface $urlGenerator,
        private McpClientGuides $clientGuides,
        #[Autowire('%env(csv:MCP_ALLOWED_HOSTS)%')]
        private array $allowedHosts,
        #[Autowire('%env(bool:MCP_MAIL_READ_ENABLED)%')]
        private bool $mailReadEnabled,
        #[Autowire('%env(bool:MCP_MAIL_SEND_ENABLED)%')]
        private bool $mailSendEnabled,
    ) {
    }

    #[AdminRoute('/', name: 'index')]
    public function __invoke(Request $request): Response
    {
        $endpointUrl = $this->urlGenerator->generate(self::ENDPOINT_ROUTE, referenceType: UrlGeneratorInterface::ABSOLUTE_URL);

        return new Response($this->twig->render('admin/mcp_setup/index.html.twig', [
            'endpointUrl' => $endpointUrl,
            'refusedHost' => $this->refusedHost((string) $request->headers->get('Host')),
            'clients' => $this->clientGuides->forEndpoint($endpointUrl),
            'tokenVariable' => McpClientGuides::TOKEN_VARIABLE,
            'scopes' => array_map(fn (ApiTokenScope $scope): array => ['scope' => $scope, 'offSetting' => $this->settingThatIsOff($scope)], ApiTokenScope::ALL),
            'actingScopes' => $this->mailSendEnabled ? [ApiTokenScope::ManageWrite, ApiTokenScope::MailSend] : [ApiTokenScope::ManageWrite],
            'mailReadAvailable' => $this->mailReadEnabled,
        ]));
    }

    /**
     * Applies the rule of the endpoint's DnsRebindingProtectionMiddleware: the raw Host header without
     * its port, ignoring case. A reverse proxy that rewrites the header is refused there, too.
     */
    private function refusedHost(string $hostHeader): ?string
    {
        $hostname = str_starts_with($hostHeader, '[')
            ? substr($hostHeader, 0, (int) strpos($hostHeader, ']') + 1)
            : explode(':', $hostHeader, 2)[0];
        $hostname = strtolower($hostname);

        if (\in_array('*', $this->allowedHosts, true) || \in_array($hostname, array_map(strtolower(...), $this->allowedHosts), true)) {
            return null;
        }

        return $hostname;
    }

    /**
     * The mail scopes are always listed, so that users learn what they could ask an administrator to
     * switch on.
     */
    private function settingThatIsOff(ApiTokenScope $scope): ?string
    {
        return match ($scope) {
            ApiTokenScope::MailRead => $this->mailReadEnabled ? null : 'MCP_MAIL_READ_ENABLED',
            ApiTokenScope::MailSend => $this->mailSendEnabled ? null : 'MCP_MAIL_SEND_ENABLED',
            default => null,
        };
    }
}
