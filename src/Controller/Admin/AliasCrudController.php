<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin;

use App\Entity\Alias;
use App\Entity\User;
use App\Service\Security\Roles;
use App\Service\Security\Voter\DomainAdminVoter;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminRoute(path: '/alias', name: 'alias')]
#[IsGranted(Roles::ROLE_DOMAIN_ADMIN)]
class AliasCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Alias::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Alias')
            ->setEntityLabelInPlural('Aliases')
            ->setSearchFields(['name', 'destination', 'domain.name'])
            ->setDefaultSort(['domain' => 'ASC', 'name' => 'ASC'])
            ->setPageTitle(Crud::PAGE_EDIT, static fn (Alias $alias) => \sprintf('Edit Alias %s', $alias))
            ->setEntityPermission(DomainAdminVoter::VIEW);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $domain = AssociationField::new('domain')
            ->setRequired(true)
            ->hideWhenUpdating()
            ->setPermission(Roles::ROLE_ADMIN)
            ->setSortProperty('name');
        $name = TextField::new('name')
            ->setRequired(false)
            ->setHelp('Leave empty to create a catch all address.');
        $destination = EmailField::new('destination');

        return [$domain, $name, $destination];
    }

    #[\Override]
    public function createEntity(string $entityFqcn): Alias
    {
        $entity = parent::createEntity($entityFqcn);
        \assert($entity instanceof Alias);

        $user = $this->getUser();

        if ($user instanceof User && null !== $user->getDomain()) {
            $entity->setDomain($user->getDomain());
        }

        return $entity;
    }

    #[\Override]
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        if ($this->isGranted(Roles::ROLE_DOMAIN_ADMIN) && !$this->isGranted(Roles::ROLE_ADMIN)) {
            $user = $this->getUser();

            if ($user instanceof User) {
                if (null === $user->getDomain()) {
                    throw new \RuntimeException('Domain admin user has no domain');
                }

                $qb
                    ->andWhere('entity.domain = :domain')
                    ->setParameter('domain', $user->getDomain());
            }
        }

        return $qb;
    }
}
