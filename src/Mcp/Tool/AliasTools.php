<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Tool;

use App\Entity\Alias;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\ToolAccess;
use App\Mcp\ToolDeniedException;
use App\Mcp\ToolDomainResolver;
use App\Mcp\ToolEntityWriter;
use App\Mcp\ToolPage;
use App\Repository\AliasRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\AliasPolicy;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\DomainScopePolicy;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class AliasTools
{
    public function __construct(
        private AliasRepository $aliases,
        private AliasPolicy $aliasPolicy,
        private DomainScopePolicy $domainScopePolicy,
        private CurrentActor $currentActor,
        private ToolDomainResolver $domainResolver,
        private ToolEntityWriter $writer,
        private ToolAuditLog $auditLog,
    ) {
    }

    /**
     * @param int $limit  Maximum number of aliases to return, at most 100
     * @param int $offset Number of aliases to skip
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    #[McpTool(
        name: 'alias_list',
        description: 'Lists the mail aliases you manage, sorted by domain and name.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::DomainAdmin)]
    public function list(int $limit = ToolPage::DEFAULT_LIMIT, int $offset = 0): array
    {
        $queryBuilder = $this->aliases->createQueryBuilder('alias')
            ->join('alias.domain', 'aliasDomain')
            ->orderBy('aliasDomain.name', 'ASC')
            ->addOrderBy('alias.name', 'ASC')
            ->addOrderBy('alias.destination', 'ASC');
        $this->domainScopePolicy->restrictToVisibleDomains($queryBuilder, $this->currentActor->get());

        return ToolPage::of($queryBuilder, $limit, $offset, $this->present(...));
    }

    /**
     * @param int $id The ID from alias_list
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'alias_get',
        description: 'Returns one mail alias.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::DomainAdmin)]
    public function get(int $id): array
    {
        return $this->present($this->findManaged($this->currentActor->get(), $id));
    }

    /**
     * @param string      $name        Local part of the address, lowercase. An empty name catches all mail to the domain
     * @param string      $destination The address mail is forwarded to
     * @param string|null $domain      Domain name. Defaults to your own domain; only admins can choose another one
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'alias_create',
        description: 'Creates a mail alias, which forwards mail to the destination.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::DomainAdmin)]
    public function create(string $name, string $destination, ?string $domain = null): array
    {
        $actor = $this->currentActor->get();

        $alias = new Alias();
        $alias->setName($name);
        $alias->setDestination($destination);
        $this->domainScopePolicy->applyDefaultDomain($actor, $alias);

        if (null !== $domain) {
            $alias->setDomain($this->domainResolver->chosenBy($actor, $domain));
        }

        $this->auditLog->target('alias', $this->address($alias));

        $this->writer->validate($alias);
        $this->writer->save($alias);

        return $this->present($alias);
    }

    /**
     * @param int         $id          The ID from alias_list
     * @param string|null $name        Local part of the address, lowercase. An empty name catches all mail to the domain
     * @param string|null $destination The address mail is forwarded to
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'alias_update',
        description: 'Updates a mail alias; arguments left out stay as they are. The domain cannot be changed.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::DomainAdmin)]
    public function update(int $id, ?string $name = null, ?string $destination = null): array
    {
        $alias = $this->findManaged($this->currentActor->get(), $id);

        if (null !== $name) {
            $alias->setName($name);
        }

        if (null !== $destination) {
            $alias->setDestination($destination);
        }

        $this->writer->validate($alias);
        $this->writer->save($alias);

        return $this->present($alias);
    }

    /**
     * @param int $id The ID from alias_list
     *
     * @return array{deleted: true}
     */
    #[McpTool(
        name: 'alias_delete',
        description: 'Deletes a mail alias.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::DomainAdmin)]
    public function delete(int $id): array
    {
        $actor = $this->currentActor->get();
        $alias = $this->findManaged($actor, $id);

        $this->aliasPolicy->denyUnlessCanDelete($actor, $alias);
        $this->writer->remove($alias);

        return ['deleted' => true];
    }

    private function findManaged(Actor $actor, int $id): Alias
    {
        $this->auditLog->target('alias', $id);
        $alias = $this->aliases->find($id);

        if (null === $alias || !$this->domainScopePolicy->canManage($actor, $alias)) {
            throw new ToolDeniedException();
        }

        return $alias;
    }

    private function address(Alias $alias): string
    {
        return \sprintf('%s@%s', $alias->getName(), $alias->getDomain()?->getName() ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Alias $alias): array
    {
        return [
            'id' => $alias->getId(),
            'address' => $this->address($alias),
            'name' => $alias->getName(),
            'domain' => $alias->getDomain()?->getName(),
            'destination' => $alias->getDestination(),
        ];
    }
}
