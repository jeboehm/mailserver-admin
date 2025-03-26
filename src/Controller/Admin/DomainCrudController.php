<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Service\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(Roles::ROLE_ADMIN)]
class DomainCrudController extends AbstractCrudController
{
    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Domain::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name']);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $name = TextField::new('name');
        $id = IdField::new('id', 'ID');
        $dkimEnabled = BooleanField::new('dkimEnabled');
        $dkimSelector = TextField::new('dkimSelector');
        $dkimPrivateKey = TextareaField::new('dkimPrivateKey');
        $users = AssociationField::new('users');
        $aliases = AssociationField::new('aliases');

        if (Crud::PAGE_DETAIL === $pageName) {
            return [$id, $name, $dkimEnabled, $dkimSelector, $dkimPrivateKey, $users, $aliases];
        }

        if (Crud::PAGE_NEW === $pageName) {
            return [$name];
        }

        if (Crud::PAGE_EDIT === $pageName) {
            return [$name];
        }

        return [$name, $aliases, $users];
    }
}
