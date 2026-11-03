<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\ToolAccess;
use App\Mcp\ToolDeniedException;
use App\Mcp\ToolDomainResolver;
use App\Mcp\ToolEntityWriter;
use App\Mcp\ToolPage;
use App\Repository\UserRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\DomainScopePolicy;
use App\Service\Security\Access\UserPolicy;
use App\Service\UserChangeListener;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class UserTools
{
    private const array PASSWORD_FIELD = ['plainPassword' => 'password'];

    public function __construct(
        private UserRepository $users,
        private UserPolicy $userPolicy,
        private DomainScopePolicy $domainScopePolicy,
        private CurrentActor $currentActor,
        private ToolDomainResolver $domainResolver,
        private ToolEntityWriter $writer,
        private ToolAuditLog $auditLog,
        private UserChangeListener $userChangeListener,
    ) {
    }

    /**
     * @param int $limit  Maximum number of users to return, at most 100
     * @param int $offset Number of users to skip
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    #[McpTool(
        name: 'user_list',
        description: 'Lists the mail users you manage, sorted by domain and name.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::DomainAdmin)]
    public function list(int $limit = ToolPage::DEFAULT_LIMIT, int $offset = 0): array
    {
        $queryBuilder = $this->users->createQueryBuilder('user')
            ->join('user.domain', 'userDomain')
            ->orderBy('userDomain.name', 'ASC')
            ->addOrderBy('user.name', 'ASC');
        $this->domainScopePolicy->restrictToVisibleDomains($queryBuilder, $this->currentActor->get());

        return ToolPage::of($queryBuilder, $limit, $offset, $this->present(...));
    }

    /**
     * @param int $id The ID from user_list
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'user_get',
        description: 'Returns one mail user. Passwords are never returned.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::DomainAdmin, target: 'user:{id}')]
    public function get(int $id): array
    {
        return $this->present($this->findManaged($this->currentActor->get(), $id));
    }

    /**
     * @param string      $name         Local part of the address, lowercase
     * @param string      $password     Password of the new user. It passes through the context of the agent and a failing call can write it to the error log.
     * @param string|null $domain       Domain name. Defaults to your own domain; only admins can choose another one
     * @param int         $quota        Disk space in megabytes, 0 for unlimited
     * @param bool        $enabled      Whether the user can log in
     * @param bool|null   $admin        Whether the user is an admin. Only admins can set this
     * @param bool        $domain_admin Whether the user can manage their domain
     * @param bool        $send_only    Whether the user can only send mail and receives none
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'user_create',
        description: 'Creates a mail user. The password is validated and never returned.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::DomainAdmin, target: 'user:{name}@{domain}')]
    public function create(
        string $name,
        #[\SensitiveParameter]
        string $password,
        ?string $domain = null,
        int $quota = 0,
        bool $enabled = true,
        ?bool $admin = null,
        bool $domain_admin = false,
        bool $send_only = false,
    ): array {
        $actor = $this->currentActor->get();

        $user = new User();
        $user->setName($name);
        $this->domainScopePolicy->applyDefaultDomain($actor, $user);

        if (null !== $domain) {
            $user->setDomain($this->domainResolver->chosenBy($actor, $domain));
        }

        $this->auditLog->target('user', (string) $user);

        $this->setAdminFlag($actor, $user, $admin);
        $user->setQuota($quota);
        $user->setEnabled($enabled);
        $user->setDomainAdmin($domain_admin);
        $user->setSendOnly($send_only);
        $user->setPlainPassword($password);

        $this->save($user, ['Default', 'register']);

        return $this->present($user);
    }

    /**
     * @param int         $id           The ID from user_list
     * @param string|null $password     New password. Leave out to keep the current one. It passes through the context of the agent and a failing call can write it to the error log.
     * @param int|null    $quota        Disk space in megabytes, 0 for unlimited
     * @param bool|null   $enabled      Whether the user can log in. Disabling a user revokes their API tokens
     * @param bool|null   $admin        Whether the user is an admin. Only admins can set this
     * @param bool|null   $domain_admin Whether the user can manage their domain
     * @param bool|null   $send_only    Whether the user can only send mail and receives none
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'user_update',
        description: 'Updates a mail user; arguments left out stay as they are. Name and domain cannot be changed. On your own user, role and enabled state stay as they are. Changing a password revokes the API tokens of that user, including this one if it is your own.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::DomainAdmin, target: 'user:{id}')]
    public function update(
        int $id,
        #[\SensitiveParameter]
        ?string $password = null,
        ?int $quota = null,
        ?bool $enabled = null,
        ?bool $admin = null,
        ?bool $domain_admin = null,
        ?bool $send_only = null,
    ): array {
        $actor = $this->currentActor->get();
        $user = $this->findManaged($actor, $id);

        $this->setAdminFlag($actor, $user, $admin);

        if (null !== $quota) {
            $user->setQuota($quota);
        }

        if (null !== $enabled) {
            $user->setEnabled($enabled);
        }

        if (null !== $domain_admin) {
            $user->setDomainAdmin($domain_admin);
        }

        if (null !== $send_only) {
            $user->setSendOnly($send_only);
        }

        $this->userPolicy->keepOwnRoleAndEnabledState($actor, $user);

        if (null !== $password) {
            $user->setPlainPassword($password);
            $this->save($user, ['Default', 'change-password']);
        } else {
            $this->save($user, ['Default']);
        }

        return $this->present($user);
    }

    /**
     * @param int $id The ID from user_list
     *
     * @return array{deleted: true}
     */
    #[McpTool(
        name: 'user_delete',
        description: 'Deletes a mail user. You cannot delete yourself.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::DomainAdmin, target: 'user:{id}')]
    public function delete(int $id): array
    {
        $actor = $this->currentActor->get();
        $user = $this->findManaged($actor, $id);

        $this->userPolicy->denyUnlessCanDelete($actor, $user);
        $this->writer->remove($user);

        return ['deleted' => true];
    }

    private function findManaged(Actor $actor, int $id): User
    {
        return ToolDeniedException::unlessAllowed($this->users->find($id), fn (User $user): bool => $this->domainScopePolicy->canManage($actor, $user));
    }

    private function setAdminFlag(Actor $actor, User $user, ?bool $admin): void
    {
        if (null === $admin) {
            return;
        }

        if (!$this->userPolicy->canSetAdminFlag($actor)) {
            throw new ToolDeniedException();
        }

        $user->setAdmin($admin);
    }

    /**
     * The forms set an empty password to get the hashing listener to run, which does nothing for a
     * user whose password already is empty. Hashing right here works for everybody, and the plain
     * password does not stay on the entity.
     *
     * @param list<string> $groups
     */
    private function save(User $user, array $groups): void
    {
        $this->writer->validate($user, $groups, self::PASSWORD_FIELD);

        $this->userChangeListener->processUserPassword($user);
        $user->setPlainPassword(null);

        $this->writer->save($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->getId(),
            'address' => (string) $user,
            'name' => $user->getName(),
            'domain' => $user->getDomain()?->getName(),
            'quota' => $user->getQuota(),
            'enabled' => $user->getEnabled(),
            'admin' => $user->isAdmin(),
            'domain_admin' => $user->isDomainAdmin(),
            'send_only' => $user->getSendOnly(),
        ];
    }
}
