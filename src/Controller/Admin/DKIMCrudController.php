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
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use DomainException;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class DKIMCrudController extends AbstractCrudController
{
    private FormatterService $formatterService;
    private KeyGenerationService $keyGenerationService;

    public function __construct(FormatterService $formatterService, KeyGenerationService $keyGenerationService)
    {
        $this->formatterService = $formatterService;
        $this->keyGenerationService = $keyGenerationService;
    }

    public static function getEntityFqcn(): string
    {
        return Domain::class;
    }

    public function edit(AdminContext $context)
    {
        $parameters = parent::edit($context);

        if ($parameters instanceof KeyValueStore && null !== ($entityDto = $parameters->get('entity'))) {
            /** @var Domain $entity */
            $entity = $entityDto->getInstance();

            if ('' !== $entity->getDkimPrivateKey()) {
                $expectedRecord = $this->formatterService->getTXTRecord(
                    $this->keyGenerationService->extractPublicKey($entity->getDkimPrivateKey()),
                    KeyGenerationService::DIGEST_ALGORITHM
                );
                $entity->setExpectedDnsRecord(wordwrap($expectedRecord, 40, "\n", true));
                $entity->setCurrentDnsRecord(wordwrap($entity->getDkimStatus()->getCurrentRecord(), 40, "\n", true));
            }
        }

        return $parameters;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $helpMessage = 'If you enable DKIM for this domain, all outgoing mails will have a DKIM signature attached. You should set up the DNS record before doing this. After you have generated a private key, a DNS record is provided that needs to be added to your domain\'s zone.';

        return $crud
            ->setHelp(Crud::PAGE_EDIT, $helpMessage)
            ->setHelp(Crud::PAGE_NEW, $helpMessage)
            ->setSearchFields(['name'])
            ->overrideTemplate('crud/edit', 'admin/dkim/edit.html.twig')
            ->setPageTitle(Crud::PAGE_INDEX, 'DKIM');
    }

    public function configureActions(Actions $actions): Actions
    {
        $recreateKey = Action::new(
            'recreateKey',
            'Recreate Key',
            'fas fa-certificate'
        )
            ->linkToCrudAction('recreateKey')
            ->setCssClass('btn btn-danger');

        return $actions->add(Crud::PAGE_EDIT, $recreateKey);
    }

    public function recreateKey(AdminContext $adminContext): Response
    {
        $domain = $adminContext->getEntity()->getInstance();
        $adminUrlGenerator = $this->get(AdminUrlGenerator::class);

        if (!$domain) {
            throw new DomainException('Domain not found.');
        }

        $keyPair = $this->keyGenerationService->createKeyPair();
        $domain->setDkimPrivateKey($keyPair->getPrivate());

        $this->addFlash('info', 'Private key successfully recreated. You need to update your DNS zone now.');

        $this->getDoctrine()->getManager()->flush();

        $url = $adminUrlGenerator
            ->setController(DKIMCrudController::class)
            ->setAction(Crud::PAGE_EDIT)
            ->setEntityId($domain->getId())
            ->generateUrl();

        return $this->redirect($url);
    }

    public function configureFields(string $pageName): iterable
    {
        $name = TextField::new('name')->setFormTypeOption('disabled', true);
        $dkimEnabled = BooleanField::new('dkimEnabled', 'Enabled');
        $dkimSelector = TextField::new('dkimSelector', 'Selector');
        $id = IdField::new('id', 'ID');
        $dkimStatusDkimRecordFound = BooleanField::new(
            'dkimStatus.dkimRecordFound',
            'Domain Key found'
        )->renderAsSwitch(false);
        $dkimStatusDkimRecordValid = BooleanField::new('dkimStatus.dkimRecordValid', 'Record valid')->renderAsSwitch(
            false
        );

        if (Crud::PAGE_DETAIL === $pageName) {
            return [$id, $name, $dkimEnabled, $dkimSelector];
        }

        if (Crud::PAGE_NEW === $pageName) {
            return [$name, $dkimEnabled, $dkimSelector];
        }

        if (Crud::PAGE_EDIT === $pageName) {
            return [$name, $dkimEnabled, $dkimSelector];
        }

        return [$name, $dkimEnabled, $dkimStatusDkimRecordFound, $dkimStatusDkimRecordValid];
    }
}
