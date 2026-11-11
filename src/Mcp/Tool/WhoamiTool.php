<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Tool;

use App\Mcp\CurrentApiToken;
use App\Mcp\ToolAccess;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\ApiToken\SenderMode;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class WhoamiTool
{
    public function __construct(
        private CurrentActor $currentActor,
        private CurrentApiToken $currentApiToken,
    ) {
    }

    /**
     * @return array{user: string, role: string, domain: string|null, scopes: list<string>, send_as?: array{mode: string, address?: string}}
     */
    #[McpTool(
        name: 'whoami',
        description: 'Returns the authenticated user, their role and domain, the scopes of this API token and, for a token that sends or drafts mail, the sender it uses.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[ToolAccess]
    public function __invoke(): array
    {
        $actor = $this->currentActor->get();
        $token = $this->currentApiToken->get();

        $identity = [
            'user' => $actor->user->getUserIdentifier(),
            'role' => match ($actor->role) {
                ActorRole::Admin => 'admin',
                ActorRole::DomainAdmin => 'domain_admin',
                ActorRole::User => 'user',
            },
            'domain' => $actor->user->getDomain()?->getName(),
            'scopes' => array_map(
                static fn (ApiTokenScope $scope): string => $scope->value,
                $token->getScopes(),
            ),
        ];

        if (!$token->hasScope(ApiTokenScope::MailSend) && !$token->hasScope(ApiTokenScope::MailDraft)) {
            return $identity;
        }

        $sendAs = ['mode' => $token->getSenderMode()->value];
        $address = $token->getSenderAddress();

        if (SenderMode::Fixed === $token->getSenderMode() && null !== $address) {
            $sendAs['address'] = $address;
        }

        return [...$identity, 'send_as' => $sendAs];
    }
}
