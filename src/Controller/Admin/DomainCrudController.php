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
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminCrud(routePath: '/domain', routeName: 'domain')]
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
        return $crud
            ->setSearchFields(['name'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::EDIT);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name')
            ->setRequired(true)
            ->setHelp('The domain name must contain only ASCII characters. You need to use punycode if you want to use non-ASCII characters.')
            ->hideWhenUpdating();
        yield AssociationField::new('users')
            ->setSortable(false)
            ->hideOnForm();
        yield AssociationField::new('aliases')
            ->setSortable(false)
            ->hideOnForm();
    }
}
