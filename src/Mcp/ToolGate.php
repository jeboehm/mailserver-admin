<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use App\Mcp\Audit\ToolAuditLog;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ToolCallException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Every tool call passes through here, in both protocol eras: it enforces #[ToolAccess], writes the
 * audit record and turns denials into the one error that reveals nothing.
 *
 * An AccessDeniedException must not reach the SDK, which would answer with a JSON-RPC error and log
 * it as an unhandled failure.
 */
final readonly class ToolGate implements ReferenceHandlerInterface
{
    public function __construct(
        private ReferenceHandlerInterface $inner,
        private ToolAccessPolicy $policy,
        private CurrentApiToken $currentApiToken,
        private ToolAuditLog $auditLog,
    ) {
    }

    #[\Override]
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        if (!$reference instanceof ToolReference) {
            return $this->inner->handle($reference, $arguments);
        }

        $tool = $reference->tool->name;
        $token = $this->currentApiToken->get();

        if (!$this->policy->allows($reference)) {
            $this->auditLog->record($tool, ToolAuditLog::RESULT_DENIED, $token);

            throw new ToolCallException(ToolDeniedException::PUBLIC_MESSAGE);
        }

        try {
            $result = $this->inner->handle($reference, $arguments);
        } catch (\Throwable $e) {
            $denied = $e instanceof AccessDeniedException || $e instanceof ToolDeniedException;

            $this->auditLog->record($tool, match (true) {
                $denied => ToolAuditLog::RESULT_DENIED,
                $e instanceof ToolCallException => ToolAuditLog::RESULT_FAILED,
                default => ToolAuditLog::RESULT_ERROR,
            }, $token);

            throw $denied ? new ToolCallException(ToolDeniedException::PUBLIC_MESSAGE) : $e;
        }

        $this->auditLog->record($tool, ToolAuditLog::RESULT_OK, $token);

        return $result;
    }
}
