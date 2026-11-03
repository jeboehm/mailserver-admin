<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Audit;

use App\Entity\ApiToken;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The record of who did what through MCP, on its own log channel.
 *
 * ToolGate writes one record per tool call. A tool only adds what it alone knows: inject this class
 * and call target() and details(). Never pass bodies, passwords or tokens.
 */
#[WithMonologChannel('mcp_audit')]
final class ToolAuditLog implements ResetInterface
{
    public const string RESULT_OK = 'ok';
    public const string RESULT_DENIED = 'denied';
    public const string RESULT_FAILED = 'failed';
    public const string RESULT_ERROR = 'error';

    private ?string $target = null;

    /** @var array<string, mixed> */
    private array $details = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function target(string $type, int|string $id): void
    {
        $this->target = \sprintf('%s:%s', $type, $id);
    }

    /**
     * @param array<string, mixed> $details for example the recipients and Message-ID of a sent mail
     */
    public function details(array $details): void
    {
        $this->details = [...$this->details, ...$details];
    }

    public function record(string $tool, string $result, ApiToken $token): void
    {
        $owner = $token->getOwner();

        $this->logger->info('MCP tool call', [
            ...$this->details,
            'token_id' => $token->getId(),
            'user_id' => $owner->getId(),
            'user' => $owner->getUserIdentifier(),
            'tool' => $tool,
            'target' => $this->target,
            'result' => $result,
            'client_ip' => $this->requestStack->getMainRequest()?->getClientIp(),
        ]);

        $this->reset();
    }

    #[\Override]
    public function reset(): void
    {
        $this->target = null;
        $this->details = [];
    }
}
