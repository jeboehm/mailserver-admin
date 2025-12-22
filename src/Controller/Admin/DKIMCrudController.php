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
use App\Service\DKIM\KeyGenerationService;
use App\Service\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminAction;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminCrud(routePath: '/dkim', routeName: 'dkim')]
#[IsGranted(Roles::ROLE_ADMIN)]
class DKIMCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly KeyGenerationService $keyGenerationService,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return Domain::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        $helpMessage = 'If you enable DKIM for this domain, all outgoing mails will have a DKIM signature attached. You should set up the DNS record before doing this. After you have generated a private key, a DNS record is provided that needs to be added to your domain\'s zone.';

        return $crud
            ->setHelp(Crud::PAGE_EDIT, $helpMessage)
            ->setSearchFields(['name'])
            ->setDefaultSort(['name' => 'ASC'])
            ->overrideTemplate('crud/edit', 'admin/dkim/edit.html.twig')
            ->setPageTitle(Crud::PAGE_INDEX, 'DKIM');
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $recreateKeyAction = Action::new(
            'recreateKey',
            'Recreate Key',
            'fas fa-certificate'
        );

        $recreateKeyAction
            ->linkToCrudAction('recreateKey')
            ->asDangerAction()
            ->renderAsForm();

        return $actions
            ->add(Crud::PAGE_EDIT, $recreateKeyAction)
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->remove(Crud::PAGE_INDEX, Action::DELETE);
    }

    #[AdminAction('/recreate/{entityId}', routeName: 'dkim_recreate_key', methods: [Request::METHOD_POST])]
    public function recreateKey(AdminContext $adminContext): Response
    {
        $domain = $adminContext->getEntity()->getInstance();

        assert($domain instanceof Domain);

        $keyPair = $this->keyGenerationService->createKeyPair();
        $domain->setDkimPrivateKey($keyPair->getPrivate());

        $this->addFlash('info', 'Private key successfully recreated. You need to update your DNS zone now.');

        $this->entityManager->flush();

        $url = $this->adminUrlGenerator
            ->setController(DKIMCrudController::class)
            ->setAction(Crud::PAGE_EDIT)
            ->setEntityId($domain->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name')
            ->setDisabled();
        yield BooleanField::new('dkimEnabled', 'Enabled');
        yield TextField::new('dkimSelector', 'Selector')
            ->setDisabled()
            ->hideOnIndex();
        yield BooleanField::new('dkimStatus.dkimRecordFound', 'Domain Key found')
            ->renderAsSwitch(false)
            ->hideOnForm();
        yield BooleanField::new('dkimStatus.dkimRecordValid', 'Record valid')
            ->renderAsSwitch(false)
            ->hideOnForm();
    }
}
