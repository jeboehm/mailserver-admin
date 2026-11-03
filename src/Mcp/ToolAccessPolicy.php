<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use App\Service\Security\Access\CurrentActor;
use Mcp\Capability\Registry\ToolReference;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Answers "may this token use this tool", for listing and for calling alike.
 */
final readonly class ToolAccessPolicy
{
    public function __construct(
        private CurrentApiToken $currentApiToken,
        private CurrentActor $currentActor,
        #[Autowire('%env(bool:MCP_MAIL_READ_ENABLED)%')]
        private bool $mailReadEnabled,
    ) {
    }

    public function allows(ToolReference $tool): bool
    {
        $access = ToolAccess::of($tool);

        if (null === $access) {
            return false;
        }

        if (ToolFeature::MailRead === $access->feature && !$this->mailReadEnabled) {
            return false;
        }

        if (null !== $access->scope && !$this->currentApiToken->get()->hasScope($access->scope)) {
            return false;
        }

        return $this->currentActor->get()->role->isAtLeast($access->minimumRole);
    }
}
