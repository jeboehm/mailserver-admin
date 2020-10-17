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
use App\Entity\Domain;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\CrudUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\User\UserInterface;

class DashboardController extends AbstractDashboardController
{
    /**
     * @Route("/", name="admin_index")
     */
    public function index(): Response
    {
        $routeBuilder = $this->get(CrudUrlGenerator::class)->build();

        return $this->redirect($routeBuilder->setController(DomainCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('mailserver-admin');
    }

    public function configureCrud(): Crud
    {
        return Crud::new();
    }

    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)->displayUserAvatar(false);
    }

    public function configureMenuItems(): iterable
    {
        $routeBuilder = $this->get(CrudUrlGenerator::class)->build();

        yield MenuItem::linkToCrud('Domain', 'fas fa-globe', Domain::class);
        yield MenuItem::linkToCrud('User', 'fas fa-user', User::class);
        yield MenuItem::linkToCrud('Alias', 'far fa-list-alt', Alias::class);

        yield MenuItem::section('Other', 'fas fa-folder-open');
        yield MenuItem::linkToUrl('DKIM', 'fas fa-shield-alt', $routeBuilder->setController(DKIMCrudController::class)->setAction(Action::INDEX)->generateUrl());
        yield MenuItem::linkToUrl('Webmail', 'fas fa-folder-open', '/webmail')->setLinkRel('noreferrer');
    }
}
