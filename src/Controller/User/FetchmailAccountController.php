<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\User;

use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Service\Security\Roles;
use App\Service\Security\Voter\FetchmailAccountVoter;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminCrud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminCrud(routePath: '/fetchmail-account', routeName: 'fetchmail')]
#[IsGranted(Roles::ROLE_USER)]
class FetchmailAccountController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return FetchmailAccount::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setSearchFields(['host', 'username'])
            ->setEntityLabelInSingular('Fetchmail Account')
            ->setEntityLabelInPlural('Fetchmail Accounts')
            ->setEntityPermission(FetchmailAccountVoter::VIEW)
            ->setHelp(Crud::PAGE_INDEX, 'Fetchmail accounts are used to fetch mails from other mail servers.');
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $user = AssociationField::new('user')
            ->setPermission(Roles::ROLE_ADMIN);

        $host = TextField::new('host');
        $port = NumberField::new('port')
            ->hideOnIndex();

        $protocol = ChoiceField::new('protocol')
            ->setChoices([
                'POP3' => 'pop3',
                'IMAP' => 'imap',
            ])
            ->hideOnIndex();

        $username = TextField::new('username');

        $password = TextField::new('password')
            ->setFormType(PasswordType::class)
            ->hideOnIndex()
            ->hideOnDetail();

        if (Crud::PAGE_EDIT === $pageName) {
            $password
                ->setHelp('Leave empty to keep the current password.')
                ->setRequired(false)
                ->setFormTypeOption('empty_data', fn (FormInterface $form) => $form->getData());
        }

        $ssl = BooleanField::new('ssl', 'SSL')
            ->setHelp('Use SSL to connect to the server. Use only with SSL-only connections or implicit TLS.')
            ->hideOnIndex();

        $sslVerify = BooleanField::new('verifySsl', 'SSL Verify')
            ->setHelp('Verify the SSL certificate of the server.')
            ->hideOnIndex();

        // runtime infos
        $lastRun = DateTimeField::new('lastRun')
            ->hideOnForm()
            ->formatValue(function ($value) {
                if (null === $value) {
                    return 'N/A';
                }

                return $value->format('Y-m-d H:i:s');
            });
        $isSuccess = BooleanField::new('isSuccess')
            ->hideOnForm()
            ->renderAsSwitch(false)
            ->setDisabled();
        $lastLog = TextareaField::new('lastLog')
            ->hideOnIndex()
            ->setEmptyData('')
            ->setLabel('Last run log')
            ->setHelp('The last log of the fetchmail run. Can help to identify issues.')
            ->setDisabled();

        return [
            $user,
            $host,
            $port,
            $protocol,
            $username,
            $password,
            $ssl,
            $sslVerify,
            $lastRun,
            $isSuccess,
            $lastLog,
        ];
    }

    #[\Override]
    public function createEntity(string $entityFqcn)
    {
        $entity = parent::createEntity($entityFqcn);
        $user = $this->getUser();
        assert($entity instanceof FetchmailAccount);

        if ($user instanceof User) {
            $entity->setUser($user);
        }

        return $entity;
    }
}
