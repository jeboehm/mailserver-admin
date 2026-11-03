<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Tool;

use App\Entity\Domain;
use App\Mcp\Audit\ToolAuditLog;
use App\Mcp\ToolAccess;
use App\Mcp\ToolDeniedException;
use App\Mcp\ToolEntityWriter;
use App\Mcp\ToolPage;
use App\Repository\DomainRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\DomainPolicy;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class DomainTools
{
    public function __construct(
        private DomainRepository $domains,
        private DomainPolicy $domainPolicy,
        private CurrentActor $currentActor,
        private ToolEntityWriter $writer,
        private ToolAuditLog $auditLog,
    ) {
    }

    /**
     * @param int $limit  Maximum number of domains to return, at most 100
     * @param int $offset Number of domains to skip
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    #[McpTool(
        name: 'domain_list',
        description: 'Lists mail domains, sorted by name, with their number of users and aliases.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::Admin)]
    public function list(int $limit = ToolPage::DEFAULT_LIMIT, int $offset = 0): array
    {
        return ToolPage::of(
            $this->domains->createQueryBuilder('domain')->orderBy('domain.name', 'ASC'),
            $limit,
            $offset,
            $this->present(...),
        );
    }

    /**
     * @param string $name The domain name, in punycode if it is not ASCII
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'domain_create',
        description: 'Creates a mail domain. A DKIM key is generated for it, but DKIM stays disabled.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::Admin, target: 'domain:{name}')]
    public function create(string $name): array
    {
        $domain = new Domain();
        $domain->setName(mb_strtolower($name));
        $this->auditLog->target('domain', $domain->getName());

        $this->writer->validate($domain);
        $this->writer->save($domain);

        return $this->present($domain);
    }

    /**
     * @param int $id The ID from domain_list
     *
     * @return array{deleted: true}
     */
    #[McpTool(
        name: 'domain_delete',
        description: 'Deletes a mail domain. Only a domain without users and aliases can be deleted.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::Admin, target: 'domain:{id}')]
    public function delete(int $id): array
    {
        $domain = $this->domains->find($id) ?? throw new ToolDeniedException();

        $this->domainPolicy->denyUnlessCanDelete($this->currentActor->get(), $domain);
        $this->writer->remove($domain);

        return ['deleted' => true];
    }

    /**
     * Reads nothing of the domain's DKIM runtime data, which takes a DNS lookup per domain.
     *
     * @return array<string, mixed>
     */
    private function present(Domain $domain): array
    {
        return [
            'id' => $domain->getId(),
            'name' => $domain->getName(),
            'dkimEnabled' => $domain->getDkimEnabled(),
            'userCount' => \count($domain->getUsers()),
            'aliasCount' => \count($domain->getAliases()),
        ];
    }
}
