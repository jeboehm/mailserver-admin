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
use App\Repository\DomainRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\DKIM\DKIMStatusService;
use App\Service\DKIM\KeyRecreationService;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The DKIM page of the web interface as tools. Every result is built by status(), which must never
 * include the private key.
 *
 * @phpstan-type DkimStatusOutput array{domain: string, enabled: bool, selector: string, record_found: bool, record_valid: bool, expected_record: string, current_record: string, issues: list<string>}
 */
final readonly class DkimTool
{
    public function __construct(
        private DomainRepository $domainRepository,
        private DKIMStatusService $statusService,
        private KeyRecreationService $keyRecreationService,
        private CurrentActor $currentActor,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private ToolAuditLog $auditLog,
    ) {
    }

    /**
     * @return DkimStatusOutput
     */
    #[McpTool(
        name: 'dkim_status',
        description: 'Returns whether DKIM is enabled for a domain, its selector, whether the DNS record was found and is valid, and the record to publish.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::Admin, target: 'domain:{domain}')]
    public function status(string $domain): array
    {
        return $this->describe($this->findDomain($domain));
    }

    /**
     * @return DkimStatusOutput
     */
    #[McpTool(
        name: 'dkim_enable',
        description: 'Enables DKIM signing for a domain, creating a key if it has none. Publish expected_record in DNS: receivers reject signatures they cannot verify.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::Admin, target: 'domain:{domain}')]
    public function enable(string $domain): array
    {
        return $this->setEnabled($this->findDomain($domain), true);
    }

    /**
     * @return DkimStatusOutput
     */
    #[McpTool(
        name: 'dkim_disable',
        description: 'Disables DKIM signing for a domain. The key is kept, so enabling it again needs no DNS change.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::Admin, target: 'domain:{domain}')]
    public function disable(string $domain): array
    {
        return $this->setEnabled($this->findDomain($domain), false);
    }

    /**
     * @return DkimStatusOutput
     */
    #[McpTool(
        name: 'dkim_recreate_key',
        description: 'Replaces the DKIM key of a domain. The published DNS record no longer matches afterwards, so expected_record has to be published again. Only runs with confirm set to true.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, minimumRole: ActorRole::Admin, target: 'domain:{domain}')]
    public function recreateKey(string $domain, bool $confirm): array
    {
        $entity = $this->findDomain($domain);

        if (!$confirm) {
            throw new ToolCallException('Nothing was changed. The published DNS record stops matching once the key is replaced; call this again with confirm set to true to go ahead.');
        }

        $this->keyRecreationService->recreate($this->currentActor->get(), $entity);

        return $this->describe($entity);
    }

    /**
     * @throws ToolDeniedException
     */
    private function findDomain(string $name): Domain
    {
        $name = rtrim(mb_strtolower($name), '.');
        $this->auditLog->target('domain', $name);

        return $this->domainRepository->findOneBy(['name' => $name]) ?? throw new ToolDeniedException();
    }

    /**
     * @return DkimStatusOutput
     */
    private function setEnabled(Domain $domain, bool $enabled): array
    {
        $domain->setDkimEnabled($enabled);

        $violations = $this->validator->validate($domain);

        if (0 !== $violations->count()) {
            throw new ToolCallException(implode(' ', array_map(static fn (ConstraintViolationInterface $violation): string => \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage()), iterator_to_array($violations, false))));
        }

        $this->entityManager->flush();

        return $this->describe($domain);
    }

    /**
     * @return DkimStatusOutput
     */
    private function describe(Domain $domain): array
    {
        $status = $this->statusService->getStatus($domain);

        return [
            'domain' => $domain->getName(),
            'enabled' => $domain->getDkimEnabled(),
            'selector' => $domain->getDkimSelector(),
            'record_found' => $status->isDkimRecordFound(),
            'record_valid' => $status->isDkimRecordValid(),
            'expected_record' => $status->getExpectedRecord(),
            'current_record' => $status->getCurrentRecord(),
            'issues' => $status->getIssues(),
        ];
    }
}
