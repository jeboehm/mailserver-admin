<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\Alias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method Alias|null  find($id, $lockMode = null, $lockVersion = null)
 * @method Alias|null  findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method Alias[]     findAll()
 * @method list<Alias> findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 *
 * @extends ServiceEntityRepository<Alias>
 */
class AliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alias::class);
    }

    /**
     * Matches a stored destination only if it equals the lower-cased address: how Postfix treats a
     * destination in another case is unverified (docker-mailserver ADR-0004), and PostgreSQL compares
     * case-sensitively anyway. The MySQL collations used so far do not, so a stored destination in
     * another case is filtered out here.
     *
     * @return list<Alias>
     */
    public function findByDestination(string $destination): array
    {
        $destination = mb_strtolower($destination);

        /** @var list<Alias> $aliases */
        $aliases = $this->createQueryBuilder('alias')
            ->andWhere('alias.destination = :destination')
            ->setParameter('destination', $destination)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($aliases, static fn (Alias $alias): bool => $alias->getDestination() === $destination));
    }
}
