<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @method User|null find($id, $lockMode = null, $lockVersion = null)
 * @method User|null findOneBy(array $criteria, array $orderBy = null)
 * @method User[]    findAll()
 * @method User[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByEmailAddress(string $emailAddress): ?User
    {
        $parts = explode('@', $emailAddress, 2);

        if (2 !== \count($parts)) {
            return null;
        }

        $qb = $this->createQueryBuilder('user');
        $qb
            ->join('user.domain', 'domain')
            ->andWhere($qb->expr()->eq('user.name', ':localPart'))
            ->andWhere($qb->expr()->eq('domain.name', ':domainPart'))
            ->setParameter('localPart', $parts[0])
            ->setParameter('domainPart', $parts[1]);

        return $qb->getQuery()->getOneOrNullResult();
    }

    #[\Override]
    public function loadUserByIdentifier(string $identifier): ?UserInterface
    {
        return $this->findOneByEmailAddress((string) $identifier);
    }
}
