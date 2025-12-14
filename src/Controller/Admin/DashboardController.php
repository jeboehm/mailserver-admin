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
use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Service\Security\Roles;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/', routeName: 'admin_index')]
#[IsGranted(Roles::ROLE_USER)]
class DashboardController extends AbstractDashboardController
{
    #[\Override]
    public function index(): Response
    {
        return $this->render('admin/dashboard/index.html.twig');
    }

    #[\Override]
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()->setTitle('mailserver-admin');
    }

    #[\Override]
    public function configureUserMenu(UserInterface $user): UserMenu
    {
        return parent::configureUserMenu($user)
            ->displayUserName(false)
            ->displayUserAvatar(false);
    }

    #[\Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Manage');
        yield MenuItem::linkToCrud('Domain', 'fa fa-globe', Domain::class)
            ->setPermission(Roles::ROLE_ADMIN);
        yield MenuItem::linkToCrud('User', 'fa fa-user', User::class)
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);
        yield MenuItem::linkToCrud('Alias', 'far fa-list-alt', Alias::class)
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);

        yield MenuItem::section('External');
        yield MenuItem::linkToCrud('Fetchmail', 'far fa-envelope', FetchmailAccount::class)
            ->setPermission(Roles::ROLE_USER);

        yield MenuItem::section('Security');
        yield MenuItem::linkToCrud('DKIM', 'fa fa-shield-alt', Domain::class)
            ->setController(DKIMCrudController::class)
            ->setPermission(Roles::ROLE_ADMIN);

        yield MenuItem::section('Tools');
        yield MenuItem::linkToUrl('Webmail', 'fa fa-envelope', '/webmail')
            ->setLinkRel('noreferrer');
        yield MenuItem::linkToUrl('Rspamd', 'fa fa-filter', '/rspamd')
            ->setLinkRel('noreferrer')
            ->setPermission(Roles::ROLE_ADMIN);

        yield MenuItem::section('Help');
        yield MenuItem::linkToUrl('Help', 'fa fa-book', 'https://jeboehm.github.io/docker-mailserver/')
            ->setLinkRel('noreferrer')
            ->setLinkTarget('_blank');
        yield MenuItem::linkToUrl('Report a bug', 'fa fa-bug', 'https://github.com/jeboehm/docker-mailserver/issues')
            ->setLinkRel('noreferrer')
            ->setLinkTarget('_blank');
    }
}
