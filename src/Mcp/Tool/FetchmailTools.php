<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Mcp\Tool;

use App\Entity\FetchmailAccount;
use App\Entity\User;
use App\Mcp\ToolAccess;
use App\Mcp\ToolDeniedException;
use App\Mcp\ToolEntityWriter;
use App\Mcp\ToolPage;
use App\Repository\FetchmailAccountRepository;
use App\Repository\UserRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\Security\Access\Actor;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\FetchmailAccountPolicy;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class FetchmailTools
{
    public function __construct(
        private FetchmailAccountRepository $accounts,
        private UserRepository $users,
        private FetchmailAccountPolicy $policy,
        private CurrentActor $currentActor,
        private ToolEntityWriter $writer,
    ) {
    }

    /**
     * @param int $limit  Maximum number of accounts to return, at most 100
     * @param int $offset Number of accounts to skip
     *
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    #[McpTool(
        name: 'fetchmail_list',
        description: 'Lists the fetchmail accounts you manage, which fetch mail from other servers. Passwords are never returned.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead)]
    public function list(int $limit = ToolPage::DEFAULT_LIMIT, int $offset = 0): array
    {
        $queryBuilder = $this->accounts->createQueryBuilder('account')
            ->orderBy('account.host', 'ASC')
            ->addOrderBy('account.username', 'ASC');
        $this->policy->restrictToVisibleAccounts($queryBuilder, $this->currentActor->get());

        return ToolPage::of($queryBuilder, $limit, $offset, $this->present(...));
    }

    /**
     * @param int $id The ID from fetchmail_list
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'fetchmail_get',
        description: 'Returns one fetchmail account. Its password is never returned.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, target: 'fetchmail:{id}')]
    public function get(int $id): array
    {
        return $this->present($this->findManaged($this->currentActor->get(), $id));
    }

    /**
     * @param string      $host       Host name of the server to fetch from
     * @param string      $username   Login at that server
     * @param string      $password   Password at that server. It passes through the context of the agent and a failing call can write it to the error log.
     * @param string|null $owner      Address of the user the mail is fetched for. Defaults to yourself
     * @param string      $protocol   imap or pop3
     * @param int         $port       Port of the server
     * @param bool        $ssl        Whether to connect with SSL, for SSL-only connections or implicit TLS
     * @param bool        $verify_ssl Whether to verify the certificate of the server
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'fetchmail_create',
        description: 'Creates a fetchmail account. The password is stored and can never be read back.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, target: 'fetchmail:{username}@{host}')]
    public function create(
        string $host,
        string $username,
        #[\SensitiveParameter]
        string $password,
        ?string $owner = null,
        string $protocol = 'imap',
        int $port = 143,
        bool $ssl = false,
        bool $verify_ssl = true,
    ): array {
        $actor = $this->currentActor->get();

        $account = new FetchmailAccount();
        $account->setUser(null === $owner ? $this->policy->defaultOwner($actor) : $this->assignableOwner($actor, $owner));
        $account->setHost($host);
        $account->setUsername($username);
        $account->setPassword($password);
        $account->setProtocol($protocol);
        $account->setPort($port);
        $account->setSsl($ssl);
        $account->setVerifySsl($verify_ssl);

        $this->writer->validate($account);
        $this->writer->save($account);

        return $this->present($account);
    }

    /**
     * @param int         $id         The ID from fetchmail_list
     * @param string|null $owner      Address of the user the mail is fetched for
     * @param string|null $host       Host name of the server to fetch from
     * @param string|null $username   Login at that server
     * @param string|null $password   New password at that server. Leave out to keep the current one. It passes through the context of the agent and a failing call can write it to the error log.
     * @param string|null $protocol   imap or pop3
     * @param int|null    $port       Port of the server
     * @param bool|null   $ssl        Whether to connect with SSL, for SSL-only connections or implicit TLS
     * @param bool|null   $verify_ssl Whether to verify the certificate of the server
     *
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'fetchmail_update',
        description: 'Updates a fetchmail account; arguments left out stay as they are. The password can be replaced, but never read back.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, target: 'fetchmail:{id}')]
    public function update(
        int $id,
        ?string $owner = null,
        ?string $host = null,
        ?string $username = null,
        #[\SensitiveParameter]
        ?string $password = null,
        ?string $protocol = null,
        ?int $port = null,
        ?bool $ssl = null,
        ?bool $verify_ssl = null,
    ): array {
        $actor = $this->currentActor->get();
        $account = $this->findManaged($actor, $id);

        if (null !== $owner) {
            $account->setUser($this->assignableOwner($actor, $owner));
        }

        if (null !== $host) {
            $account->setHost($host);
        }

        if (null !== $username) {
            $account->setUsername($username);
        }

        if (null !== $password) {
            $account->setPassword($password);
        }

        if (null !== $protocol) {
            $account->setProtocol($protocol);
        }

        if (null !== $port) {
            $account->setPort($port);
        }

        if (null !== $ssl) {
            $account->setSsl($ssl);
        }

        if (null !== $verify_ssl) {
            $account->setVerifySsl($verify_ssl);
        }

        $this->writer->validate($account);
        $this->writer->save($account);

        return $this->present($account);
    }

    /**
     * @param int $id The ID from fetchmail_list
     *
     * @return array{deleted: true}
     */
    #[McpTool(
        name: 'fetchmail_delete',
        description: 'Deletes a fetchmail account.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageWrite, target: 'fetchmail:{id}')]
    public function delete(int $id): array
    {
        $actor = $this->currentActor->get();
        $account = $this->findManaged($actor, $id);

        $this->policy->denyUnlessCanDelete($actor, $account);
        $this->writer->remove($account);

        return ['deleted' => true];
    }

    private function findManaged(Actor $actor, int $id): FetchmailAccount
    {
        return ToolDeniedException::unlessAllowed($this->accounts->find($id), fn (FetchmailAccount $account): bool => $this->policy->canManage($actor, $account));
    }

    private function assignableOwner(Actor $actor, string $address): User
    {
        $owner = $this->users->findOneByEmailAddress($address);

        if (null === $owner || !$this->policy->canAssignTo($actor, $owner)) {
            throw new ToolDeniedException();
        }

        return $owner;
    }

    /**
     * Leaves out the log of the last run: it holds what remote servers answered, and nothing
     * guarantees it stays free of credentials.
     *
     * @return array<string, mixed>
     */
    private function present(FetchmailAccount $account): array
    {
        return [
            'id' => $account->getId(),
            'owner' => $account->getUser()?->getUserIdentifier(),
            'host' => $account->getHost(),
            'protocol' => $account->getProtocol(),
            'port' => $account->getPort(),
            'username' => $account->getUsername(),
            'ssl' => $account->isSsl(),
            'verify_ssl' => $account->isVerifySsl(),
            'last_run' => $account->lastRun?->format(\DATE_ATOM),
            'last_run_succeeded' => $account->isSuccess,
        ];
    }
}
