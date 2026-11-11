<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller\User;

use App\Entity\ApiToken;
use App\Form\Type\ApiTokenType;
use App\Service\ApiToken\ApiTokenIssuer;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\ApiToken\SenderBinding;
use App\Service\ApiToken\SenderMode;
use App\Service\MailSend\OwnedAddresses;
use App\Service\MailSend\SenderResolver;
use App\Service\Security\Access\ApiTokenPolicy;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lists and revokes API tokens. Tokens can be neither edited nor deleted, so the record of a revoked
 * token stays visible until its owner is removed.
 *
 * @extends AbstractCrudController<ApiToken>
 */
#[AdminRoute(path: '/api-token', name: 'api_token')]
#[IsGranted(Roles::ROLE_USER)]
class ApiTokenCrudController extends AbstractCrudController
{
    public const string REVOKE_CSRF_TOKEN_ID = 'api-token-revoke';

    public function __construct(
        private readonly CurrentActor $currentActor,
        private readonly ApiTokenPolicy $apiTokenPolicy,
        private readonly ApiTokenIssuer $apiTokenIssuer,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly OwnedAddresses $ownedAddresses,
        private readonly SenderResolver $senderResolver,
        #[Autowire('%env(bool:CSRF_ENABLED)%')]
        private readonly bool $csrfEnabled,
    ) {
    }

    #[\Override]
    public static function getEntityFqcn(): string
    {
        return ApiToken::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('API token')
            ->setEntityLabelInPlural('API tokens')
            ->setSearchFields(['name'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            // The dropdown ignores an action's template, so the revoke form would lose its CSRF token.
            ->showEntityActionsInlined()
            ->setHelp(Crud::PAGE_INDEX, 'API tokens let agents use this server through its MCP endpoint, with your role and within the scopes of the token. They stop working when your password changes or your account is disabled.');
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $revoke = Action::new('revoke', 'Revoke', 'fa fa-ban')
            ->linkToCrudAction('revoke')
            ->asDangerAction()
            ->renderAsForm()
            ->setTemplatePath('admin/api_token/revoke_action.html.twig')
            ->displayIf(static fn (ApiToken $token): bool => !$token->isRevoked());

        return $actions
            ->add(Crud::PAGE_INDEX, $revoke)
            ->disable(Action::EDIT, Action::DELETE, Action::BATCH_DELETE, Action::DETAIL);
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');

        if ($this->apiTokenPolicy->canSeeOwners($this->currentActor->get())) {
            yield TextField::new('owner.userIdentifier', 'Owner')->setSortable(false);
        }

        yield TextField::new('scopeNames', 'Scopes')->setSortable(false);
        yield Field::new('sender', 'Sender')
            ->setVirtual(true)
            ->setSortable(false)
            ->setTemplatePath('admin/api_token/sender_field.html.twig')
            ->formatValue(fn (mixed $value, ApiToken $token): ?bool => $this->senderResolver->isBoundAddressOwned($token));
        yield DateTimeField::new('createdAt', 'Created');
        yield DateTimeField::new('expiresAt', 'Expires');
        yield DateTimeField::new('lastUsedAt', 'Last used');
        yield DateTimeField::new('revokedAt', 'Revoked');
    }

    #[\Override]
    public function createIndexQueryBuilder(
        SearchDto $searchDto,
        EntityDto $entityDto,
        FieldCollection $fields,
        FilterCollection $filters,
    ): QueryBuilder {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        $this->apiTokenPolicy->restrictToVisibleTokens($qb, $this->currentActor->get());

        return $qb;
    }

    /**
     * Answers the POST directly instead of redirecting, because a redirect would have to carry the
     * secret through the session.
     */
    #[\Override]
    public function new(AdminContext $context): KeyValueStore|Response
    {
        $maxLifetimeDays = $this->apiTokenIssuer->maxLifetimeDays();
        $user = $this->currentActor->get()->user;
        $form = $this->createForm(ApiTokenType::class, [
            'name' => '',
            'scopes' => ApiTokenScope::PRESELECTED,
            'senderMode' => SenderMode::Primary,
            'senderAddress' => null,
            'lifetimeDays' => $maxLifetimeDays,
        ], [
            'max_lifetime_days' => $maxLifetimeDays,
            // Every role gets its own addresses only, the same set the tools accept.
            'owned_addresses' => $this->ownedAddresses->of($user),
        ]);

        $form->handleRequest($context->getRequest());

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/api_token/new.html.twig', ['form' => $form]);
        }

        $data = $form->getData();
        $issued = $this->apiTokenIssuer->issue(
            $user,
            $data['name'],
            $data['scopes'],
            $data['lifetimeDays'],
            // The form asks for an address only for a token that sends or drafts.
            ApiTokenScope::anySenderBound($data['scopes']) ? SenderBinding::chosen($data['senderMode'], $data['senderAddress']) : null,
        );

        $response = $this->render('admin/api_token/created.html.twig', ['issued' => $issued]);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param AdminContext<ApiToken> $context
     */
    #[AdminRoute(path: '/revoke/{entityId}', name: 'revoke', options: ['methods' => [Request::METHOD_POST]])]
    public function revoke(AdminContext $context): Response
    {
        if ($this->csrfEnabled && !$this->isCsrfTokenValid(self::REVOKE_CSRF_TOKEN_ID, $context->getRequest()->request->getString('token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $token = $context->getEntity()->getInstance();
        \assert($token instanceof ApiToken);

        $this->apiTokenPolicy->denyUnlessCanRevoke($this->currentActor->get(), $token);

        $token->revoke();
        $this->entityManager->flush();

        $this->addFlash('info', 'The token has been revoked.');

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Crud::PAGE_INDEX)->generateUrl());
    }
}
