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
use App\Mcp\ToolDomainResolver;
use App\Mcp\ToolTimeLimit;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\DnsWizard\DnsWizardRow;
use App\Service\DnsWizard\ExpectedHostIps;
use App\Service\DnsWizard\HostIpResolver;
use App\Service\DnsWizard\TimeBoxedDnsValidation;
use App\Service\Security\Access\ActorRole;
use App\Service\Security\Access\CurrentActor;
use App\Service\Security\Access\DomainPolicy;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * The DNS wizard as a tool.
 *
 * A domain takes about twenty lookups, none of which PHP can time out, and on a stack whose resolver
 * did not answer each one took five seconds. So the lookups run on a budget and the call answers with
 * what it has. The time limit is only raised to give the lookup in flight room to return: past the
 * limit the client would get a bare HTTP 500 instead of a result.
 *
 * Results are deliberately not cached as in the wizard: right after dkim_recreate_key or a DNS change
 * a stale answer would be wrong in the way that matters most to an agent.
 *
 * @phpstan-type DnsRowOutput array{subject: string, record_type: string, expected: list<string>, actual: list<string>, status: string, message: string}
 */
final readonly class DnsValidateTool
{
    private const int DEFAULT_LIMIT = 10;
    private const int MAX_LIMIT = 50;
    private const float BUDGET_SECONDS = 20.0;
    private const int TIME_LIMIT_SECONDS = 60;

    public function __construct(
        private DomainPolicy $domainPolicy,
        private CurrentActor $currentActor,
        private HostIpResolver $hostIpResolver,
        private TimeBoxedDnsValidation $validation,
        private RequestStack $requestStack,
        private ToolAuditLog $auditLog,
        #[Autowire('%env(default::string:MAILNAME)%')]
        private ?string $mailname,
    ) {
    }

    /**
     * @param string|null $domain only validate this domain
     * @param int         $limit  how many domains to validate in this call, at most 50
     * @param string|null $cursor next_cursor of the previous call, to continue where it stopped
     *
     * @return array{
     *     mailname: string,
     *     expected_host_ips: list<string>,
     *     mail_host: list<DnsRowOutput>|null,
     *     domains: list<array{domain: string, checks: list<DnsRowOutput>}>,
     *     timed_out: list<string>,
     *     total: int,
     *     next_cursor: string|null,
     *     note: string|null
     * }
     */
    #[McpTool(
        name: 'dns_validate',
        description: 'Checks the DNS records of the mail host and of your domains, as the DNS wizard does. Results come in pages: while next_cursor is set, call again with it. The mail host is only checked in the first call. Domains in timed_out were not checked, so nothing is known about their records.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: true),
    )]
    #[ToolAccess(scope: ApiTokenScope::ManageRead, minimumRole: ActorRole::DomainAdmin, target: 'domain:{domain}')]
    public function __invoke(?string $domain = null, int $limit = self::DEFAULT_LIMIT, ?string $cursor = null): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ToolCallException(\sprintf('limit must be between 1 and %d.', self::MAX_LIMIT));
        }

        if (null !== $cursor && !ctype_digit($cursor)) {
            throw new ToolCallException('cursor must be the next_cursor of an earlier call.');
        }

        $domains = $this->visibleDomains($domain);
        $offset = (int) $cursor;
        $page = \array_slice($domains, $offset, $limit);

        ToolTimeLimit::raiseTo(self::TIME_LIMIT_SECONDS);

        $mailname = $this->mailname ?? (string) $this->requestStack->getMainRequest()?->getHost();
        $expectedHostIps = $this->resolveExpectedHostIps();
        $result = $this->validation->run($mailname, $expectedHostIps, $page, null === $cursor, self::BUDGET_SECONDS);

        $position = $offset + $result->consumed;

        return [
            'mailname' => $mailname,
            'expected_host_ips' => $expectedHostIps->all(),
            'mail_host' => null === $result->mailHost ? null : $this->rows($result->mailHost),
            'domains' => array_map(
                fn (string $name, array $rows): array => ['domain' => $name, 'checks' => $this->rows($rows)],
                array_keys($result->domains),
                array_values($result->domains),
            ),
            'timed_out' => $result->timedOut,
            'total' => \count($domains),
            'next_cursor' => $result->mailHostTimedOut || $position < \count($domains) ? (string) $position : null,
            'note' => match (true) {
                $result->mailHostTimedOut => 'The DNS lookups for the mail host took too long, so it was not checked. Call again with next_cursor to check the domains.',
                [] !== $result->timedOut => 'The DNS lookups for this domain took too long. This is not a missing record: the resolver did not answer in time.',
                $result->consumed < \count($page) => 'The DNS lookups took too long to finish this page. Call again with next_cursor for the rest.',
                default => null,
            },
        ];
    }

    /**
     * @throws ToolDeniedException
     *
     * @return list<Domain>
     */
    private function visibleDomains(?string $name): array
    {
        $domains = $this->domainPolicy->domainsForDnsValidation($this->currentActor->get());

        if (null === $name) {
            return $domains;
        }

        $name = ToolDomainResolver::normalise($name);
        $this->auditLog->target('domain', $name);

        foreach ($domains as $domain) {
            if ($name === mb_strtolower($domain->getName())) {
                return [$domain];
            }
        }

        throw new ToolDeniedException();
    }

    private function resolveExpectedHostIps(): ExpectedHostIps
    {
        try {
            return $this->hostIpResolver->resolveExpectedHostIps();
        } catch (HttpClientException|\InvalidArgumentException) {
            throw new ToolCallException('The public addresses of this server could not be determined, so its DNS records cannot be checked. Set MAILSERVER_HOST_IPS to a comma-separated list of them, or try again.');
        }
    }

    /**
     * @param list<DnsWizardRow> $rows
     *
     * @return list<DnsRowOutput>
     */
    private function rows(array $rows): array
    {
        return array_map(static fn (DnsWizardRow $row): array => [
            'subject' => $row->subject,
            'record_type' => $row->recordType,
            'expected' => $row->expectedValues,
            'actual' => $row->actualValues,
            'status' => $row->status->value,
            'message' => $row->message,
        ], $rows);
    }
}
