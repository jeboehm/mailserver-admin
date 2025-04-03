<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\PasswordService;
use App\Service\Security\Roles;
use App\Service\Security\Voter\DomainAdminVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminCrud(routePath: '/user', routeName: 'user')]
#[IsGranted(Roles::ROLE_DOMAIN_ADMIN)]
class UserCrudController extends AbstractCrudController
{
    public function __construct(private readonly PasswordService $passwordService)
    {
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
            ->setPageTitle(Crud::PAGE_EDIT, fn (User $user) => sprintf('Edit User %s', $user))
            ->hideNullValues()
            ->setEntityPermission(DomainAdminVoter::VIEW);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $domain = AssociationField::new('domain')
            ->setRequired(true)
            ->hideWhenUpdating()
            ->setPermission(Roles::ROLE_ADMIN);
        $name = TextField::new('name')
            ->hideWhenUpdating();
        $admin = BooleanField::new('admin')
            ->setPermission(Roles::ROLE_ADMIN);
        $domainAdmin = BooleanField::new('domainAdmin')
            ->setHelp('Domain admins can manage all users in their domain')
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);
        $enabled = BooleanField::new('enabled');
        $sendOnly = BooleanField::new('sendOnly')
            ->setHelp('Send only accounts are not allowed to receive mails');
        $quota = IntegerField::new('quota')
            ->setHelp('How much space the account can use (in megabytes)')
            ->formatValue(fn (?int $value) => $value ? sprintf('%d MB', $value) : 'Unlimited');
        $plainPassword = Field::new('plainPassword')
            ->setFormType(PasswordType::class)
            ->setLabel('Password')
            ->setRequired(true)
            ->onlyOnForms();

        if (Crud::PAGE_EDIT === $pageName) {
            $plainPassword
                ->setHelp('Leave empty to keep the current password.')
                ->setRequired(false)
                ->setFormTypeOption('empty_data', fn (FormInterface $form) => $form->getData());
        }

        return [$domain, $name, $plainPassword, $admin, $domainAdmin, $enabled, $sendOnly, $quota];
    }

    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        assert($entityInstance instanceof User);
        $this->passwordService->processUserPassword($entityInstance);

        $user = $this->getUser();

        /*
         * If user is trying to update themself, we need to keep the permissions and enabled state.
         */
        if ($user instanceof User && $user === $entityInstance) {
            $entityInstance->setEnabled(true);
            $entityInstance->setAdmin($this->isGranted(Roles::ROLE_ADMIN));
            $entityInstance->setDomainAdmin(
                $this->isGranted(Roles::ROLE_DOMAIN_ADMIN)
                && !$this->isGranted(Roles::ROLE_ADMIN)
            );
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    #[\Override]
    public function createEntity(string $entityFqcn): User
    {
        $entity = parent::createEntity($entityFqcn);
        assert($entity instanceof User);

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
