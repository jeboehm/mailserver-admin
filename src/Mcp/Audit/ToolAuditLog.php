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
 * ToolGate writes one record per tool call, with the target declared in #[ToolAccess]. A tool only adds
 * what it alone knows: inject this class and call target() and details(). Never pass bodies, passwords
 * or tokens.
 */
#[WithMonologChannel('mcp_audit')]
final class ToolAuditLog implements ResetInterface
{
    public const string RESULT_OK = 'ok';
    public const string RESULT_DENIED = 'denied';
    public const string RESULT_FAILED = 'failed';
    public const string RESULT_ERROR = 'error';

    private const int MAX_TARGET_LENGTH = 255;

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
        $this->target = $this->clean(\sprintf('%s:%s', $type, $id));
    }

    /**
     * Fills a template such as "user:{id}" with tool arguments. Without any usable argument there is no
     * target. A denied call is refused before its arguments are checked, so they can be anything.
     *
     * @param array<string, mixed> $arguments
     */
    public function targetFrom(string $template, array $arguments): void
    {
        $found = false;

        $target = preg_replace_callback('/\{(\w+)\}/', static function (array $placeholder) use ($arguments, &$found): string {
            $value = $arguments[$placeholder[1]] ?? null;

            if (!\is_string($value) && !\is_int($value)) {
                return '?';
            }

            $found = true;

            return (string) $value;
        }, $template);

        if ($found) {
            $this->target = $this->clean((string) $target);
        }
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

    /**
     * A target can come straight from a tool argument, which must not be able to forge a log line.
     */
    private function clean(string $target): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/u', '', mb_scrub($target, 'UTF-8')), 0, self::MAX_TARGET_LENGTH);
    }
}
