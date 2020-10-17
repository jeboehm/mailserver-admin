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
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class DomainCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Domain::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setSearchFields(['name']);
    }

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
