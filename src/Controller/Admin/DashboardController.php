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
use App\Service\Security\Voter\LocalUserVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\UserMenu;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/', routeName: 'admin')]
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
        return Dashboard::new()
            ->setTitle('mailserver-admin')
            ->setFaviconPath('favicon.svg');
    }

    #[\Override]
    public function configureUserMenu(UserInterface $user): UserMenu
    {
        $userMenu = parent::configureUserMenu($user)
            ->displayUserName(false)
            ->displayUserAvatar(false);

        if (null !== $this->getUserEmail()) {
            $userMenu->setGravatarEmail($this->getUserEmail());
        }

        $userMenu->addMenuItems(
            [
                MenuItem::linkToRoute('Change Password', 'fa fa-key', 'admin_change_password_index')
                    ->setPermission(LocalUserVoter::KEY),
            ]
        );

        return $userMenu;
    }

    #[\Override]
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Home', 'fa fa-home');

        yield MenuItem::section('Manage')
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);
        yield MenuItem::linkToCrud('Domain', 'fa fa-globe', Domain::class)
            ->setPermission(Roles::ROLE_ADMIN);
        yield MenuItem::linkToCrud('User', 'fa fa-user', User::class)
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);
        yield MenuItem::linkToCrud('Alias', 'fa fa-list-alt', Alias::class)
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);

        yield MenuItem::section('External');
        yield MenuItem::linkToCrud('Fetchmail', 'fa fa-envelope', FetchmailAccount::class)
            ->setPermission(Roles::ROLE_USER);

        yield MenuItem::section('Security');
        yield MenuItem::linkToRoute('Change Password', 'fa fa-key', 'admin_change_password_index')
            ->setPermission(LocalUserVoter::KEY);
        yield MenuItem::linkToCrud('DKIM', 'fa fa-shield-alt', Domain::class)
            ->setController(DKIMCrudController::class)
            ->setPermission(Roles::ROLE_ADMIN);

        yield MenuItem::section('Tools');
        yield MenuItem::linkToRoute('DNS wizard', 'fa fa-network-wired', 'admin_dns_wizard_index')
            ->setPermission(Roles::ROLE_DOMAIN_ADMIN);
        yield MenuItem::linkToRoute('iOS/MacOS Profile', 'fa fa-mobile-alt', 'admin_mobileconfig_download')
            ->setPermission(LocalUserVoter::KEY);

        $webmail = MenuItem::linkToUrl('Webmail', 'fa fa-envelope', '/webmail')
            ->setLinkRel('noreferrer');

        if ($email = $this->getUserEmail()) {
            $webmail = MenuItem::linkToUrl('Webmail', 'fa fa-envelope', '/webmail/?_user=' . urlencode($email))
                ->setLinkRel('noreferrer');
        }

        yield $webmail;

        yield MenuItem::linkToUrl('Rspamd', 'fa fa-filter', '/rspamd')
            ->setLinkRel('noreferrer')
            ->setPermission(Roles::ROLE_ADMIN);

        yield MenuItem::section('Help');
        yield MenuItem::linkToUrl('Docs', 'fa fa-book', 'https://jeboehm.github.io/docker-mailserver/')
            ->setLinkRel('noreferrer')
            ->setLinkTarget('_blank');
        yield MenuItem::linkToUrl('Report a bug', 'fa fa-bug', 'https://github.com/jeboehm/docker-mailserver/issues')
            ->setLinkRel('noreferrer')
            ->setLinkTarget('_blank');
    }

    private function getUserEmail(): ?string
    {
        $user = $this->getUser();

        if (null === $user) {
            return null;
        }

        $email = $user->getUserIdentifier();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }
}
