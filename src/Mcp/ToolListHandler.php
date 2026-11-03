<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists only the tools the token may use. Custom handlers run before the SDK's, so this shadows its
 * list handler in both protocol eras. Hiding a tool enforces nothing, {@see ToolGate} does that.
 *
 * There is no pagination: the whole catalogue fits well below the SDK's page size of 50.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final readonly class ToolListHandler implements RequestHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'mcp.server.default.registry')]
        private RegistryInterface $registry,
        private ToolAccessPolicy $policy,
    ) {
    }

    #[\Override]
    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    #[\Override]
    public function handle(Request $request, SessionInterface $session): Response
    {
        $tools = [];

        foreach ($this->registry->getTools()->references as $tool) {
            if ($this->policy->allows($this->registry->getTool($tool->name))) {
                $tools[] = $tool;
            }
        }

        return new Response($request->getId(), new ListToolsResult($tools));
    }
}
