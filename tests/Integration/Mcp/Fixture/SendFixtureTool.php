<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp\Fixture;

use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\ToolAccess;
use App\Mcp\ToolDeniedException;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\ActorRole;
use Mcp\Capability\Attribute\McpTool;

/**
 * A tool with a scope and a role requirement, as the only production tool has neither. Only
 * registered in the test environment.
 */
final readonly class SendFixtureTool
{
    public function __construct(private ToolAuditLog $auditLog)
    {
    }

    #[McpTool(name: 'fixture_admin_send')]
    #[ToolAccess(scope: ApiTokenScope::MailSend, minimumRole: ActorRole::Admin)]
    public function adminSend(): string
    {
        return 'ran';
    }

    #[McpTool(name: 'fixture_touch_foreign')]
    #[ToolAccess]
    public function touchForeign(string $password): string
    {
        $this->auditLog->target('user', 12);

        throw new ToolDeniedException();
    }
}
