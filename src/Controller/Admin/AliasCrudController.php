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
use App\Service\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminCrud(routePath: '/alias', routeName: 'alias')]
#[IsGranted(Roles::ROLE_ADMIN)]
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
            ->setSearchFields(['id', 'name', 'destination']);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $domain = AssociationField::new('domain');
        $name = TextField::new('name');
        $destination = EmailField::new('destination');
        $id = IdField::new('id', 'ID');

        $name->setRequired(false);
        $name->setHelp('Leave empty to create a catch all address.');

        $domain->setRequired(true);

        if (Crud::PAGE_DETAIL === $pageName) {
            return [$id, $name, $destination, $domain];
        }

        if (Crud::PAGE_NEW === $pageName) {
            return [$domain, $name, $destination];
        }

        if (Crud::PAGE_EDIT === $pageName) {
            return [$domain, $name, $destination];
        }

        return [$domain, $name, $destination];
    }
}
