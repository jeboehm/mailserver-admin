<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DependencyInjection\Compiler;

use App\Mcp\ToolGate;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Sets what the MCP bundle has no working option for, on its server builder. This depends on the
 * bundle's service id, but its version is pinned and the integration tests fail if this breaks.
 */
final class McpServerPass implements CompilerPassInterface
{
    /**
     * Nothing is ever published, and an open "subscriptions/listen" stream holds one of the PHP threads
     * the web interface needs as well, up to PHP's time limit by default. The bundle's own
     * "subscriptions.lifetime" is ignored unless a notification bus is configured.
     */
    private const float SUBSCRIPTION_LIFETIME_SECONDS = 1.0;

    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('mcp.server.default.builder')
            ->addMethodCall('setReferenceHandler', [new Reference(ToolGate::class)])
            ->addMethodCall('setSubscriptionLifetime', [self::SUBSCRIPTION_LIFETIME_SECONDS]);
    }
}
