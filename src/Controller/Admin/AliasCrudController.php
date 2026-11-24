<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin;

use App\Controller\GuardedDeleteCrudController;
use App\Entity\Alias;
use App\Service\Security\Access\AliasPolicy;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\DomainScopePolicy;
use App\Service\Security\Roles;
use App\Service\Security\Voter\AliasModifyVoter;
use App\Service\Security\Voter\DomainAdminVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminRoute(path: '/alias', name: 'alias')]
#[IsGranted(Roles::ROLE_DOMAIN_ADMIN)]
class AliasCrudController extends GuardedDeleteCrudController
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly DomainScopePolicy $domainScopePolicy,
        private readonly AliasPolicy $aliasPolicy,
    ) {
    }

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
        if ($this->domainScopePolicy->canChooseDomain($this->currentActor->get())) {
            yield AssociationField::new('domain')
                ->setRequired(true)
                ->hideWhenUpdating()
                ->setSortProperty('name');
        }

        yield TextField::new('name')
            ->setRequired(false)
            ->setHelp('Leave empty to create a catch all address.');
        yield EmailField::new('destination');
    }

    #[\Override]
    public function createEntity(string $entityFqcn): Alias
    {
        $entity = parent::createEntity($entityFqcn);
        \assert($entity instanceof Alias);

        $this->domainScopePolicy->applyDefaultDomain($this->currentActor->get(), $entity);

        return $entity;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $actor = $this->currentActor->get();
        $aliasPolicy = $this->aliasPolicy;
        $offerDeleteIfAllowed = static fn (Action $action): Action => $action->displayIf(static fn (Alias $alias) => $aliasPolicy->canDelete($actor, $alias));

        return parent::configureActions($actions)
            ->setPermission(Action::EDIT, AliasModifyVoter::MODIFY)
            ->update(Crud::PAGE_INDEX, Action::DELETE, $offerDeleteIfAllowed)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $offerDeleteIfAllowed);
    }

    /**
     * Refuses a new alias on an admin's address or to an admin's destination, and a change onto one:
     * the edit permission only covers the values the alias had before.
     */
    #[\Override]
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof Alias);
        $this->denyUnlessCanUseAddress($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof Alias);
        $this->denyUnlessCanUseAddress($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $this->domainScopePolicy->restrictToVisibleDomains($qb, $this->currentActor->get());

        return $qb;
    }

    #[\Override]
    protected function denyUnlessCanDelete(object $entity): void
    {
        \assert($entity instanceof Alias);

        $this->aliasPolicy->denyUnlessCanDelete($this->currentActor->get(), $entity);
    }

    private function denyUnlessCanUseAddress(Alias $alias): void
    {
        $actor = $this->currentActor->get();

        $this->aliasPolicy->denyUnlessCanUseAddress($actor, $alias->getDomain(), $alias->getName());
        $this->aliasPolicy->denyUnlessCanUseDestination($actor, $alias->getDestination());
    }
}
