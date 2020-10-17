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
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AliasCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Alias::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Alias')
            ->setEntityLabelInPlural('Alias')
            ->setSearchFields(['id', 'name', 'destination']);
    }

    public function configureFields(string $pageName): iterable
    {
        $domain = AssociationField::new('domain');
        $name = TextField::new('name');
        $destination = EmailField::new('destination');
        $id = IdField::new('id', 'ID');

        if (Crud::PAGE_INDEX === $pageName) {
            return [$domain, $name, $destination];
        } elseif (Crud::PAGE_DETAIL === $pageName) {
            return [$id, $name, $destination, $domain];
        } elseif (Crud::PAGE_NEW === $pageName) {
            return [$domain, $name, $destination];
        } elseif (Crud::PAGE_EDIT === $pageName) {
            return [$domain, $name, $destination];
        }
    }
}
