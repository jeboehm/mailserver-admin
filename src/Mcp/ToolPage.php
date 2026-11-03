<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Mcp\Exception\ToolCallException;

/**
 * The one shape every list tool answers with.
 */
final class ToolPage
{
    public const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 100;

    /**
     * A page out of range is refused for every tool, as a caller could take a corrected one for what it
     * asked for.
     *
     * @throws ToolCallException
     */
    public static function assertRange(int $limit, int $offset = 0, int $maxLimit = self::MAX_LIMIT): void
    {
        if ($limit < 1 || $limit > $maxLimit || $offset < 0) {
            throw new ToolCallException(\sprintf('The limit must be between 1 and %d and the offset must not be negative.', $maxLimit));
        }
    }

    /**
     * @template T of object
     *
     * @param \Closure(T): array<string, mixed> $present
     *
     * @throws ToolCallException
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public static function of(QueryBuilder $queryBuilder, int $limit, int $offset, \Closure $present): array
    {
        self::assertRange($limit, $offset);

        $queryBuilder->setFirstResult($offset)->setMaxResults($limit);
        $paginator = new Paginator($queryBuilder, false);

        $items = [];

        foreach ($paginator as $entity) {
            $items[] = $present($entity);
        }

        return ['items' => $items, 'total' => \count($paginator), 'limit' => $limit, 'offset' => $offset];
    }
}
