<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\ActorRole;
use Mcp\Capability\Registry\ToolReference;

/**
 * Declares who may use a tool. Put it next to #[McpTool]; a tool without it can be used by nobody.
 *
 * This decides about the tool as a whole. Whether the actor may touch a particular object is up to
 * the tool, through the policies in App\Service\Security\Access.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class ToolAccess
{
    public function __construct(
        public ?ApiTokenScope $scope = null,
        public ActorRole $minimumRole = ActorRole::User,
        public ?ToolFeature $feature = null,
    ) {
    }

    public static function of(ToolReference $reference): ?self
    {
        [$class, $method] = \is_array($reference->handler)
            ? $reference->handler
            : [$reference->handler, '__invoke'];

        if ($class instanceof \Closure) {
            return null;
        }

        $attributes = new \ReflectionMethod($class, $method)->getAttributes(self::class)
            ?: new \ReflectionClass($class)->getAttributes(self::class);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }
}
