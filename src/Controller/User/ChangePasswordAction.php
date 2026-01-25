<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\User;

use App\Entity\User;
use App\Form\Type\ChangePasswordType;
use App\Service\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

#[AdminRoute('/change-password', name: 'change_password')]
#[IsGranted(Roles::ROLE_USER)]
readonly class ChangePasswordAction
{
    public function __construct(
        private FormFactoryInterface $form,
        private Environment $twig,
        private Security $security,
        private EntityManagerInterface $entityManager,
        private AdminUrlGenerator $router,
    ) {
    }

    #[AdminRoute('/', name: 'index')]
    public function __invoke(Request $request): Response
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $form = $this->form->create(ChangePasswordType::class, $user);

        if ($request->isMethod(Request::METHOD_POST)) {
            $form->handleRequest($request);

            if ($form->isValid()) {
                $user->setPassword(''); // set empty password to trigger entity's lifecycle callbacks.

                $this->addFlash(
                    $request->getSession(),
                    'info',
                    'Your password has been updated.'
                );

                $this->entityManager->flush();

                return new RedirectResponse(
                    $this->router->setRoute('admin_change_password_index')->generateUrl()
                );
            }
        }

        return new Response(
            $this->twig->render(
                'admin/change_password/index.html.twig',
                [
                    'form' => $form->createView(),
                ]
            )
        );
    }

    private function addFlash(SessionInterface $session, string $type, mixed $message): void
    {
        if (!$session instanceof FlashBagAwareSessionInterface) {
            throw new \LogicException(\sprintf('You cannot use the addFlash method because class "%s" doesn\'t implement "%s".', get_debug_type($session), FlashBagAwareSessionInterface::class));
        }

        $session->getFlashBag()->add($type, $message);
    }
}
