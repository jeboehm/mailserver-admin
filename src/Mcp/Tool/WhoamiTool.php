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
use App\Service\MailSend\OwnedAddresses;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class WhoamiTool
{
    public function __construct(
        private CurrentActor $currentActor,
        private CurrentApiToken $currentApiToken,
        private OwnedAddresses $ownedAddresses,
    ) {
    }

    /**
     * @return array{user: string, role: string, domain: string|null, scopes: list<string>, send_as?: array{mode: string, address?: string, addresses?: list<string>}}
     */
    #[McpTool(
        name: 'whoami',
        description: 'Returns the authenticated user, their role and domain, the scopes of this API token and, for a token that sends or drafts mail, the sender it uses: its mode and the address, or for mode "any" the addresses "from_address" accepts.',
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

        $sender = $token->getSender();

        if (null === $sender) {
            return $identity;
        }

        $sendAs = ['mode' => $sender->mode->value];

        if (null !== $sender->address) {
            $sendAs['address'] = $sender->address;
        }

        if (SenderMode::Any === $sender->mode) {
            $sendAs['addresses'] = $this->ownedAddresses->of($actor->user);
        }

        return [...$identity, 'send_as' => $sendAs];
    }
}
