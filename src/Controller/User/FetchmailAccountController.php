<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\User;

use App\Controller\GuardedDeleteCrudController;
use App\Entity\FetchmailAccount;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\FetchmailAccountPolicy;
use App\Service\Security\Roles;
use App\Service\Security\Voter\FetchmailAccountVoter;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
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

#[AdminRoute(path: '/fetchmail-account', name: 'fetchmail')]
#[IsGranted(Roles::ROLE_USER)]
class FetchmailAccountController extends GuardedDeleteCrudController
{
    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly FetchmailAccountPolicy $fetchmailAccountPolicy,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return FetchmailAccount::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setSearchFields(['host', 'username', 'user.domain.name'])
            ->setEntityLabelInSingular('Fetchmail Account')
            ->setEntityLabelInPlural('Fetchmail Accounts')
            ->setDefaultSort(['host' => 'ASC'])
            ->setEntityPermission(FetchmailAccountVoter::VIEW)
            ->setHelp(Crud::PAGE_INDEX, 'Fetchmail accounts are used to fetch mails from other mail servers.')
            ->hideNullValues();
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        $actor = $this->currentActor->get();

        if ($this->fetchmailAccountPolicy->canChooseOwner($actor)) {
            yield AssociationField::new('user')
                ->setQueryBuilder(function (QueryBuilder $qb) use ($actor): QueryBuilder {
                    $this->fetchmailAccountPolicy->restrictToAssignableOwners($qb, $actor);

                    return $qb;
                });
        }

        yield TextField::new('host');
        yield NumberField::new('port')
            ->hideOnIndex();

        yield ChoiceField::new('protocol')
            ->setChoices([
                'POP3' => 'pop3',
                'IMAP' => 'imap',
            ])
            ->hideOnIndex();

        yield TextField::new('username');

        $password = TextField::new('password')
            ->setFormType(PasswordType::class)
            ->onlyOnForms();

        if (Crud::PAGE_EDIT === $pageName) {
            $password
                ->setHelp('Leave empty to keep the current password.')
                ->setRequired(false)
                ->setFormTypeOption('empty_data', static fn (FormInterface $form) => $form->getData());
        }

        yield $password;

        yield BooleanField::new('ssl', 'SSL')
            ->setHelp('Use SSL to connect to the server. Use only with SSL-only connections or implicit TLS.')
            ->hideOnIndex();

        yield BooleanField::new('verifySsl', 'SSL Verify')
            ->setHelp('Verify the SSL certificate of the server.')
            ->hideOnIndex();

        // runtime infos
        yield DateTimeField::new('lastRun')
            ->hideOnForm()
            ->formatValue(static function (?\DateTimeInterface $value): string {
                if (null === $value) {
                    return 'N/A';
                }

                return $value->format('Y-m-d H:i:s');
            });

        yield BooleanField::new('isSuccess')
            ->hideOnForm()
            ->renderAsSwitch(false)
            ->setDisabled();

        yield TextareaField::new('lastLog')
            ->hideOnIndex()
            ->hideWhenCreating()
            ->setEmptyData('')
            ->setLabel('Last run log')
            ->setHelp('The last log of the fetchmail run. Can help to identify issues.')
            ->setDisabled();
    }

    #[\Override]
    public function createEntity(string $entityFqcn): FetchmailAccount
    {
        $entity = parent::createEntity($entityFqcn);
        \assert($entity instanceof FetchmailAccount);

        $entity->setUser($this->fetchmailAccountPolicy->defaultOwner($this->currentActor->get()));

        return $entity;
    }

    #[\Override]
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $this->fetchmailAccountPolicy->restrictToVisibleAccounts($qb, $this->currentActor->get());

        return $qb;
    }

    #[\Override]
    protected function denyUnlessCanDelete(object $entity): void
    {
        \assert($entity instanceof FetchmailAccount);

        $this->fetchmailAccountPolicy->denyUnlessCanDelete($this->currentActor->get(), $entity);
    }
}
