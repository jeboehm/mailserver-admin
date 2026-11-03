<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

/**
 * Throw this from a tool for anything the actor may not do and for anything that does not exist.
 * ToolGate answers both with the same message, so tools cannot be used to probe other domains.
 */
final class ToolDeniedException extends \RuntimeException
{
    public const string PUBLIC_MESSAGE = 'Not found or not allowed.';

    public function __construct()
    {
        parent::__construct(self::PUBLIC_MESSAGE);
    }

    /**
     * For an object looked up by a tool argument: missing and out of reach are the same to the caller.
     *
     * @template T of object
     *
     * @param T|null            $object
     * @param callable(T): bool $allowed
     *
     * @throws self
     *
     * @return T
     */
    public static function unlessAllowed(?object $object, callable $allowed): object
    {
        if (null === $object || !$allowed($object)) {
            throw new self();
        }

        return $object;
    }
}
