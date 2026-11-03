<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\Domain;
use App\Entity\User;
use App\Mcp\ToolDeniedException;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\McpClientTrait;
use Tests\Integration\Mcp\Fixture\FakeDnsLookup;

class DnsValidateToolTest extends WebTestCase
{
    use McpClientTrait;

    private string $previousTimeLimit;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FakeDnsLookup::reset();
        $this->previousTimeLimit = (string) \ini_get('max_execution_time');
        $this->enableMcp();
        $this->setEnvironment('MAILNAME', 'mail.example.com');
        $this->setEnvironment('MAILSERVER_HOST_IPS', '192.0.2.1');
        $this->client = static::createClient();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');
        $this->unsetEnvironment('MAILNAME');
        $this->unsetEnvironment('MAILSERVER_HOST_IPS');
        FakeDnsLookup::reset();
        set_time_limit((int) $this->previousTimeLimit);

        parent::tearDown();
    }

    public function testAdminSeesAllDomains(): void
    {
        $this->storeDomain('foreign.test');

        $output = $this->validate($this->findUser());

        self::assertSame(['example.com', 'foreign.test'], array_column($output['domains'], 'domain'));
        self::assertSame(2, $output['total']);
        self::assertNull($output['next_cursor']);
    }

    public function testDomainAdminOnlySeesTheirOwnDomain(): void
    {
        $this->storeDomain('foreign.test');

        $output = $this->validate($this->domainAdmin());

        self::assertSame(['example.com'], array_column($output['domains'], 'domain'));
        self::assertSame(1, $output['total']);
    }

    public function testDomainAdminMayNameTheirOwnDomain(): void
    {
        $output = $this->validate($this->domainAdmin(), ['domain' => 'example.com']);

        self::assertSame(['example.com'], array_column($output['domains'], 'domain'));
    }

    public function testDomainAdminCannotProbeAnotherDomain(): void
    {
        $this->storeDomain('foreign.test');
        $user = $this->domainAdmin();
        $secret = $this->storeToken($user, [ApiTokenScope::ManageRead]);
        $sessionId = $this->initialiseSession($secret);

        $foreign = $this->callTool($secret, $sessionId, 'dns_validate', ['domain' => 'foreign.test']);
        $unknown = $this->callTool($secret, $sessionId, 'dns_validate', ['domain' => 'unknown.test']);

        self::assertTrue($foreign['isError'] ?? false);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $foreign['content'][0]['text']);
        self::assertSame($unknown, $foreign);
    }

    public function testRegularUserIsDenied(): void
    {
        $user = $this->findUser();
        $user->setAdmin(false);
        $secret = $this->storeToken($user, [ApiTokenScope::ManageRead]);
        $sessionId = $this->initialiseSession($secret);

        self::assertNotContains('dns_validate', $this->listTools($secret, $sessionId));

        $result = $this->callTool($secret, $sessionId, 'dns_validate', ['domain' => 'example.com']);

        self::assertTrue($result['isError'] ?? false);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
    }

    public function testMissingScopeIsDenied(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageWrite, ApiTokenScope::MailRead, ApiTokenScope::MailSend]);
        $sessionId = $this->initialiseSession($secret);

        self::assertNotContains('dns_validate', $this->listTools($secret, $sessionId));

        $result = $this->callTool($secret, $sessionId, 'dns_validate');

        self::assertTrue($result['isError'] ?? false);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
    }

    public function testReportsWhatTheChecksFound(): void
    {
        FakeDnsLookup::$a['mail.example.com'] = ['192.0.2.1'];
        FakeDnsLookup::$mx['example.com'] = ['mail.example.com'];

        $output = $this->validate($this->findUser(), ['domain' => 'example.com']);

        self::assertSame('mail.example.com', $output['mailname']);
        self::assertSame(['192.0.2.1'], $output['expected_host_ips']);

        self::assertNotNull($output['mail_host']);
        $address = $this->row($output['mail_host'], 'A');
        self::assertSame('ok', $address['status']);
        self::assertSame(['192.0.2.1'], $address['actual']);

        $mx = $this->row($output['domains'][0]['checks'], 'MX');
        self::assertSame('ok', $mx['status']);
        self::assertSame('MX points to mail host', $mx['message']);

        $dkim = array_find($output['domains'][0]['checks'], static fn (array $row): bool => 'dkim._domainkey.example.com' === $row['subject']);
        self::assertNotNull($dkim);
        self::assertSame('error', $dkim['status']);
        self::assertSame('DKIM record missing or empty', $dkim['message']);
    }

    public function testPagesThroughTheDomainsAndChecksTheMailHostOnce(): void
    {
        $this->storeDomain('b.test');
        $this->storeDomain('c.test');
        $user = $this->findUser();

        $first = $this->validate($user, ['limit' => 2]);
        self::assertSame(['b.test', 'c.test'], array_column($first['domains'], 'domain'));
        self::assertNotNull($first['mail_host']);
        self::assertSame(3, $first['total']);
        self::assertIsString($first['next_cursor']);

        $second = $this->validate($this->findUser(), ['limit' => 2, 'cursor' => $first['next_cursor']]);
        self::assertSame(['example.com'], array_column($second['domains'], 'domain'));
        self::assertNull($second['mail_host']);
        self::assertNull($second['next_cursor']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPagination(): iterable
    {
        yield 'limit too low' => [['limit' => 0]];
        yield 'limit too high' => [['limit' => 51]];
        yield 'cursor not a number' => [['cursor' => 'abc']];
        yield 'negative cursor' => [['cursor' => '-1']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidPagination')]
    public function testRejectsInvalidPagination(array $arguments): void
    {
        $secret = $this->storeToken($this->findUser());

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'dns_validate', $arguments);

        self::assertTrue($result['isError'] ?? false);
        self::assertNotSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
    }

    public function testLimitIsRefusedInTheWordsOfEveryOtherTool(): void
    {
        $secret = $this->storeToken($this->findUser());

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'dns_validate', ['limit' => 51]);

        self::assertSame('The limit must be between 1 and 50 and the offset must not be negative.', $result['content'][0]['text']);
    }

    public function testRunningOutOfTimeOnTheMailHostContinuesWithTheDomains(): void
    {
        FakeDnsLookup::$outOfTimeAt = ['mail.example.com'];

        $first = $this->validate($this->findUser());
        self::assertNull($first['mail_host']);
        self::assertSame([], $first['domains']);
        self::assertSame('0', $first['next_cursor']);
        self::assertIsString($first['note']);
        self::assertStringContainsString('mail host', $first['note']);

        FakeDnsLookup::$outOfTimeAt = [];

        $second = $this->validate($this->findUser(), ['cursor' => $first['next_cursor']]);
        self::assertSame(['example.com'], array_column($second['domains'], 'domain'));
        self::assertNull($second['next_cursor']);
    }

    public function testDomainTheResolverCannotAnswerInTimeIsReportedAndSkipped(): void
    {
        $this->storeDomain('b.test');
        FakeDnsLookup::$outOfTimeAt = ['b.test'];

        $output = $this->validate($this->findUser(), ['cursor' => '0', 'limit' => 1]);

        self::assertSame([], $output['domains']);
        self::assertSame(['b.test'], $output['timed_out']);
        self::assertSame('1', $output['next_cursor'], 'The next call has to move past the domain that timed out.');
        self::assertIsString($output['note']);
        self::assertStringContainsString('not a missing record', $output['note']);
    }

    public function testAnUnavailableHostAddressIsAnErrorForTheCaller(): void
    {
        $this->setEnvironment('MAILSERVER_HOST_IPS', 'not-an-address');
        $secret = $this->storeToken($this->findUser());

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'dns_validate');

        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('MAILSERVER_HOST_IPS', $result['content'][0]['text']);
    }

    public function testRaisesAShorterTimeLimitButNeverSetsOne(): void
    {
        $user = $this->findUser();

        set_time_limit(0);
        $this->validate($user);
        self::assertSame('0', \ini_get('max_execution_time'), 'No limit has to stay no limit.');

        set_time_limit(30);
        $this->validate($this->findUser());
        self::assertSame('60', \ini_get('max_execution_time'));
    }

    public function testOutputContainsNoPrivateKey(): void
    {
        $response = $this->rawValidate($this->findUser());
        $key = $this->privateKey();

        self::assertStringContainsString('PRIVATE KEY', $key);
        self::assertStringContainsString('v=DKIM1', $response, 'The check that shows the public record has to have run.');
        self::assertStringNotContainsString('PRIVATE KEY', $response);
        self::assertStringNotContainsString(explode("\n", $key)[1], $response);
    }

    public function testNamedDomainIsAudited(): void
    {
        $this->validate($this->findUser(), ['domain' => 'example.com']);

        $handler = $this->client->getContainer()->get('app.test.mcp_audit_records');
        $records = $handler->getRecords();
        $record = end($records);
        self::assertNotFalse($record);
        self::assertSame('dns_validate', $record->context['tool']);
        self::assertSame('domain:example.com', $record->context['target']);
    }

    private function domainAdmin(): User
    {
        $user = $this->findUser();
        $user->setAdmin(false);
        $user->setDomainAdmin(true);

        return $user;
    }

    private function storeDomain(string $name): void
    {
        $domain = new Domain();
        $domain->setName($name);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($domain);
        $entityManager->flush();
    }

    private function privateKey(): string
    {
        $key = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT dkim_private_key FROM mail_domains WHERE name = :name', ['name' => 'example.com']);
        self::assertIsString($key);

        return $key;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{mailname: string, expected_host_ips: list<string>, mail_host: list<array{subject: string, record_type: string, expected: list<string>, actual: list<string>, status: string, message: string}>|null, domains: list<array{domain: string, checks: list<array{subject: string, record_type: string, expected: list<string>, actual: list<string>, status: string, message: string}>}>, timed_out: list<string>, total: int, next_cursor: string|null, note: string|null}
     */
    private function validate(User $user, array $arguments = []): array
    {
        $secret = $this->storeToken($user, [ApiTokenScope::ManageRead]);

        /** @var array{mailname: string, expected_host_ips: list<string>, mail_host: list<array{subject: string, record_type: string, expected: list<string>, actual: list<string>, status: string, message: string}>|null, domains: list<array{domain: string, checks: list<array{subject: string, record_type: string, expected: list<string>, actual: list<string>, status: string, message: string}>}>, timed_out: list<string>, total: int, next_cursor: string|null, note: string|null} $output */
        $output = $this->toolOutput($this->callTool($secret, $this->initialiseSession($secret), 'dns_validate', $arguments));

        return $output;
    }

    private function rawValidate(User $user): string
    {
        $secret = $this->storeToken($user, [ApiTokenScope::ManageRead]);
        $response = $this->mcpRequest('tools/call', ['name' => 'dns_validate', 'arguments' => new \stdClass()], 'Bearer ' . $secret, $this->initialiseSession($secret));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return (string) $response->getContent();
    }

    /**
     * @param list<array{subject: string, record_type: string, expected: list<string>, actual: list<string>, status: string, message: string}> $rows
     *
     * @return array{subject: string, record_type: string, expected: list<string>, actual: list<string>, status: string, message: string}
     */
    private function row(array $rows, string $recordType): array
    {
        $row = array_find($rows, static fn (array $row): bool => $recordType === $row['record_type']);
        self::assertNotNull($row, \sprintf('No %s row.', $recordType));

        return $row;
    }
}
