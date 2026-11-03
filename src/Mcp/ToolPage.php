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

/**
 * The one shape every list tool answers with.
 */
final class ToolPage
{
    public const int DEFAULT_LIMIT = 50;
    private const int MAX_LIMIT = 100;

    /**
     * A limit or offset out of range is corrected, and the answer states what was applied.
     *
     * @template T of object
     *
     * @param \Closure(T): array<string, mixed> $present
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public static function of(QueryBuilder $queryBuilder, int $limit, int $offset, \Closure $present): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

        $queryBuilder->setFirstResult($offset)->setMaxResults($limit);
        $paginator = new Paginator($queryBuilder, false);

        $items = [];

        foreach ($paginator as $entity) {
            $items[] = $present($entity);
        }

        return ['items' => $items, 'total' => \count($paginator), 'limit' => $limit, 'offset' => $offset];
    }
}
