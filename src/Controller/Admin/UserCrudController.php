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
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UserCrudController extends AbstractCrudController
{
    public function __construct(private PasswordService $passwordService)
    {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name']);
    }

    public function configureFields(string $pageName): iterable
    {
        $domain = AssociationField::new('domain');
        $name = TextField::new('name');
        $admin = BooleanField::new('admin');
        $enabled = BooleanField::new('enabled');
        $sendOnly = BooleanField::new('sendOnly')->setHelp('Send only accounts are not allowed to receive mails');
        $quota = IntegerField::new('quota')->setHelp('How much space the account can use (in megabytes)');
        $plainPassword = Field::new('plainPassword')->setLabel('Change password');
        $id = IdField::new('id', 'ID');

        $domain->setRequired(true);

        if (Crud::PAGE_DETAIL === $pageName) {
            return [$id, $name, $plainPassword, $admin, $enabled, $sendOnly, $quota, $domain];
        }

        if (Crud::PAGE_NEW === $pageName) {
            return [$domain, $name, $admin, $enabled, $sendOnly, $quota, $plainPassword];
        }

        if (Crud::PAGE_EDIT === $pageName) {
            return [$domain, $name, $admin, $enabled, $sendOnly, $quota, $plainPassword];
        }

        return [$domain, $name, $enabled, $sendOnly, $admin];
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->passwordService->processUserPassword($entityInstance);

        parent::updateEntity($entityManager, $entityInstance);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->passwordService->processUserPassword($entityInstance);

        parent::persistEntity($entityManager, $entityInstance);
    }
}
