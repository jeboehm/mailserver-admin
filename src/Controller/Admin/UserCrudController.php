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
use App\Entity\User;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\DomainScopePolicy;
use App\Service\Security\Access\UserPolicy;
use App\Service\Security\Roles;
use App\Service\Security\Voter\DomainAdminVoter;
use App\Service\Security\Voter\UserModifyVoter;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminRoute(path: '/user', name: 'user')]
#[IsGranted(Roles::ROLE_DOMAIN_ADMIN)]
class UserCrudController extends GuardedDeleteCrudController
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly DomainScopePolicy $domainScopePolicy,
        private readonly UserPolicy $userPolicy,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setSearchFields(['name'])
            ->setDefaultSort(['domain' => 'ASC', 'name' => 'ASC'])
            ->setSearchFields(['name', 'domain.name'])
            ->setEntityPermission(DomainAdminVoter::VIEW);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $actor = $this->currentActor->get();

        yield FormField::addColumn();
        yield TextField::new('name')
            ->hideWhenUpdating();

        if ($this->domainScopePolicy->canChooseDomain($actor)) {
            yield AssociationField::new('domain')
                ->setRequired(true)
                ->hideWhenUpdating()
                ->setSortProperty('name');
        }

        yield IntegerField::new('quota')
            ->setHelp('How much space the account can use (in megabytes).')
            ->formatValue(static fn (?int $value) => $value ? \sprintf('%d MB', $value) : 'Unlimited');

        yield Field::new('plainPassword')
            ->setFormType(RepeatedType::class)
            ->setFormTypeOption('type', PasswordType::class)
            ->setLabel('Repeat password')
            ->setRequired(Crud::PAGE_EDIT !== $pageName)
            ->setFormTypeOption('first_options', [
                'label' => 'Password',
                'help' => Crud::PAGE_EDIT === $pageName ? 'Leave empty to keep the current password.' : null,
            ])
            ->setFormTypeOption('second_options', [
                'label' => 'Repeat password',
            ])
            ->onlyOnForms();

        yield FormField::addColumn();
        yield BooleanField::new('enabled');

        if ($this->userPolicy->canSetAdminFlag($actor)) {
            yield BooleanField::new('admin');
        }

        yield BooleanField::new('domainAdmin')
            ->setHelp('Domain admins can manage all users in their domain')
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);
        yield BooleanField::new('sendOnly')
            ->setHelp('Send only accounts are not allowed to receive mails');
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        \assert($entityInstance instanceof User);

        if (!empty($entityInstance->getPlainPassword())) {
            $entityInstance->setPassword(''); // set password to trigger LifeCycleCallbacks
        }

        $this->userPolicy->keepOwnRoleAndEnabledState($this->currentActor->get(), $entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function createEntity(string $entityFqcn): User
    {
        $entity = parent::createEntity($entityFqcn);
        \assert($entity instanceof User);

        $this->domainScopePolicy->applyDefaultDomain($this->currentActor->get(), $entity);

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

        $this->domainScopePolicy->restrictToVisibleDomains($qb, $this->currentActor->get());

        return $qb;
    }

    public function configureActions(Actions $actions): Actions
    {
        $actor = $this->currentActor->get();
        $userPolicy = $this->userPolicy;
        $offerDeleteIfAllowed = static fn (Action $action): Action => $action->displayIf(static fn (User $user) => $userPolicy->canDelete($actor, $user));

        return parent::configureActions($actions)
            ->disable(Action::BATCH_DELETE)
            ->setPermission(Action::EDIT, UserModifyVoter::MODIFY)
            ->update(Crud::PAGE_INDEX, Action::DELETE, $offerDeleteIfAllowed)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $offerDeleteIfAllowed);
    }

    #[\Override]
    protected function denyUnlessCanDelete(object $entity): void
    {
        \assert($entity instanceof User);

        $this->userPolicy->denyUnlessCanDelete($this->currentActor->get(), $entity);
    }
}
