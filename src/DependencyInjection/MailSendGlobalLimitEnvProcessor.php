<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\DependencyInjection;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Turns the global send limit per minute into the two settings of its token bucket, as
 * `%env(send_bucket_size:MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE)%` and
 * `%env(send_bucket_refill:MCP_MAIL_SEND_GLOBAL_LIMIT_PER_MINUTE)%` in `framework.rate_limiter`.
 *
 * Postfix counts per rolling minute, so a bucket of a third of the limit that refills the rest evenly
 * cannot hold more than the limit in any 60 seconds. The built-in processors cannot round up, which is
 * why this exists.
 */
final readonly class MailSendGlobalLimitEnvProcessor implements EnvVarProcessorInterface
{
    #[\Override]
    public static function getProvidedTypes(): array
    {
        return [
            'send_bucket_size' => 'int',
            'send_bucket_refill' => 'string',
        ];
    }

    #[\Override]
    public function getEnv(string $prefix, string $name, \Closure $getEnv): int|string
    {
        $limit = $getEnv($name);

        if (!\is_scalar($limit) || false === filter_var($limit, \FILTER_VALIDATE_INT)) {
            throw new RuntimeException(\sprintf('The value of "%s" is not an integer.', $name));
        }

        $limit = (int) $limit;
        $size = (int) ceil($limit / 3);

        return match ($prefix) {
            'send_bucket_size' => $size,
            // Seconds until the bucket gets one token back.
            'send_bucket_refill' => \sprintf('%d seconds', (int) ceil(60 / max(1, $limit - $size))),
            default => throw new RuntimeException(\sprintf('Unsupported env var prefix "%s".', $prefix)),
        };
    }
}
