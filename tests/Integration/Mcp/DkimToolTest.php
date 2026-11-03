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
use App\Service\DKIM\FormatterService;
use App\Service\DKIM\KeyGenerationService;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\McpClientTrait;
use Tests\Integration\Mcp\Fixture\FakeDnsLookup;

class DkimToolTest extends WebTestCase
{
    use McpClientTrait;

    private const array READ_ARGUMENTS = ['domain' => 'example.com'];
    private const array RECREATE_ARGUMENTS = ['domain' => 'example.com', 'confirm' => true];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        FakeDnsLookup::reset();
        $this->enableMcp();
        $this->client = static::createClient();
        $this->redis()->del('dkim_keys');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');
        FakeDnsLookup::reset();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, ApiTokenScope}>
     */
    public static function tools(): iterable
    {
        yield 'dkim_status' => ['dkim_status', self::READ_ARGUMENTS, ApiTokenScope::ManageRead];
        yield 'dkim_enable' => ['dkim_enable', self::READ_ARGUMENTS, ApiTokenScope::ManageWrite];
        yield 'dkim_disable' => ['dkim_disable', self::READ_ARGUMENTS, ApiTokenScope::ManageWrite];
        yield 'dkim_recreate_key' => ['dkim_recreate_key', self::RECREATE_ARGUMENTS, ApiTokenScope::ManageWrite];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('tools')]
    public function testAdminMayUseTheTool(string $tool, array $arguments, ApiTokenScope $scope): void
    {
        $secret = $this->storeToken($this->findUser(), [$scope]);
        $sessionId = $this->initialiseSession($secret);

        self::assertContains($tool, $this->listTools($secret, $sessionId));
        self::assertFalse($this->callTool($secret, $sessionId, $tool, $arguments)['isError'] ?? false);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('tools')]
    public function testDomainAdminIsDeniedEvenOnTheirOwnDomain(string $tool, array $arguments, ApiTokenScope $scope): void
    {
        $this->assertDeniedFor($this->domainAdmin(), $tool, $arguments, $scope);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('tools')]
    public function testRegularUserIsDeniedEvenOnTheirOwnDomain(string $tool, array $arguments, ApiTokenScope $scope): void
    {
        $user = $this->findUser();
        $user->setAdmin(false);

        $this->assertDeniedFor($user, $tool, $arguments, $scope);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('tools')]
    public function testMissingScopeIsDenied(string $tool, array $arguments, ApiTokenScope $scope): void
    {
        $others = array_values(array_filter(ApiTokenScope::ALL, static fn (ApiTokenScope $other): bool => $other !== $scope));
        $before = $this->privateKey();

        $secret = $this->storeToken($this->findUser(), $others);
        $sessionId = $this->initialiseSession($secret);

        self::assertNotContains($tool, $this->listTools($secret, $sessionId));
        $this->assertDeniedMessage($this->callTool($secret, $sessionId, $tool, $arguments));
        self::assertSame($before, $this->privateKey());
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('tools')]
    public function testUnknownDomainGivesTheSharedError(string $tool, array $arguments, ApiTokenScope $scope): void
    {
        $secret = $this->storeToken($this->findUser());

        $this->assertDeniedMessage($this->callTool($secret, $this->initialiseSession($secret), $tool, [...$arguments, 'domain' => 'unknown.example']));
    }

    public function testStatusReportsAMissingRecordAndWhatToPublish(): void
    {
        $status = $this->adminCall('dkim_status', self::READ_ARGUMENTS);

        self::assertSame('example.com', $status['domain']);
        self::assertTrue($status['enabled']);
        self::assertSame('dkim', $status['selector']);
        self::assertFalse($status['record_found']);
        self::assertFalse($status['record_valid']);
        self::assertSame($this->expectedRecord(), $status['expected_record']);
    }

    public function testStatusRecognisesThePublishedRecord(): void
    {
        FakeDnsLookup::$txt['dkim._domainkey.example.com'] = [$this->expectedRecord()];

        $status = $this->adminCall('dkim_status', self::READ_ARGUMENTS);

        self::assertTrue($status['record_found']);
        self::assertTrue($status['record_valid']);
        self::assertSame([], $status['issues']);
    }

    public function testStatusExplainsARecordOfAnotherKeyPair(): void
    {
        FakeDnsLookup::$txt['dkim._domainkey.example.com'] = ['v=DKIM1; p=b3RoZXI='];

        $status = $this->adminCall('dkim_status', self::READ_ARGUMENTS);

        self::assertTrue($status['record_found']);
        self::assertFalse($status['record_valid']);
        self::assertSame('v=DKIM1; p=b3RoZXI=', $status['current_record']);
        self::assertStringContainsString('different key pair', $status['issues'][0]);
    }

    public function testDomainNameIsMatchedLikeTheConsoleCommands(): void
    {
        self::assertSame('example.com', $this->adminCall('dkim_status', ['domain' => 'EXAMPLE.com.'])['domain']);
    }

    public function testDisablingStopsSigningOnTheMailServer(): void
    {
        $this->redis()->hset('dkim_keys', 'dkim.example.com', $this->privateKey());

        $result = $this->adminCall('dkim_disable', self::READ_ARGUMENTS);

        self::assertFalse($result['enabled']);
        self::assertFalse($this->reloadDomain()->getDkimEnabled());
        self::assertNull($this->redis()->hget('dkim_keys', 'dkim.example.com'));
    }

    public function testEnablingSyncsTheKeyToTheMailServer(): void
    {
        $this->setDkimEnabled(false);

        $result = $this->adminCall('dkim_enable', self::READ_ARGUMENTS);

        self::assertTrue($result['enabled']);
        self::assertTrue($this->reloadDomain()->getDkimEnabled());
        self::assertSame($this->privateKey(), $this->redis()->hget('dkim_keys', 'dkim.example.com'));
    }

    public function testEnablingCreatesAndStoresAKeyWhenThereIsNone(): void
    {
        $this->storeKeylessDisabledDomain();

        $result = $this->adminCall('dkim_enable', self::READ_ARGUMENTS);

        $stored = $this->privateKey();
        self::assertStringContainsString('PRIVATE KEY', $stored);
        self::assertSame($stored, $this->redis()->hget('dkim_keys', 'dkim.example.com'));
        self::assertSame($this->expectedRecord(), $result['expected_record']);
    }

    public function testEnablingTwiceKeepsTheKey(): void
    {
        $before = $this->privateKey();

        $this->adminCall('dkim_enable', self::READ_ARGUMENTS);
        $this->adminCall('dkim_enable', self::READ_ARGUMENTS);

        self::assertSame($before, $this->privateKey());
    }

    public function testInvalidDomainIsNotSaved(): void
    {
        $domain = $this->reloadDomain();
        $domain->setName('not a domain!');
        $domain->setDkimEnabled(false);
        $this->entityManager()->flush();

        $secret = $this->storeToken($this->findUserByEntity($domain));
        $result = $this->callTool($secret, $this->initialiseSession($secret), 'dkim_enable', ['domain' => 'not a domain!']);

        self::assertTrue($result['isError']);
        self::assertStringContainsString('name', $result['content'][0]['text']);
        $this->entityManager()->clear();
        self::assertFalse($this->entityManager()->getRepository(Domain::class)->findOneBy(['name' => 'not a domain!'])?->getDkimEnabled());
    }

    public function testRecreatingReplacesTheKeyAndReturnsTheNewRecord(): void
    {
        $before = $this->privateKey();

        $result = $this->adminCall('dkim_recreate_key', self::RECREATE_ARGUMENTS);

        self::assertNotSame($before, $this->privateKey());
        self::assertSame($this->expectedRecord(), $result['expected_record']);
        self::assertSame($this->privateKey(), $this->redis()->hget('dkim_keys', 'dkim.example.com'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unconfirmedArguments(): iterable
    {
        yield 'false' => [['domain' => 'example.com', 'confirm' => false]];
        yield 'the string false' => [['domain' => 'example.com', 'confirm' => 'false']];
        yield 'missing' => [['domain' => 'example.com']];
        yield 'not a boolean' => [['domain' => 'example.com', 'confirm' => 'maybe']];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('unconfirmedArguments')]
    public function testRecreatingWithoutConfirmationChangesNothing(array $arguments): void
    {
        $before = $this->privateKey();
        $secret = $this->storeToken($this->findUser());

        $response = $this->mcpRequest('tools/call', ['name' => 'dkim_recreate_key', 'arguments' => $arguments], 'Bearer ' . $secret, $this->initialiseSession($secret));

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue(isset($body['error']) || true === ($body['result']['isError'] ?? false), (string) $response->getContent());
        self::assertSame($before, $this->privateKey());
    }

    public function testRecreatingIsDeclaredDestructiveAndRequiresConfirmation(): void
    {
        $tool = $this->describeTool('dkim_recreate_key');

        self::assertTrue($tool['annotations']['destructiveHint']);
        self::assertContains('confirm', $tool['inputSchema']['required']);
    }

    public function testStatusIsDeclaredReadOnly(): void
    {
        self::assertTrue($this->describeTool('dkim_status')['annotations']['readOnlyHint']);
    }

    public function testNoToolOutputContainsThePrivateKey(): void
    {
        FakeDnsLookup::$txt['dkim._domainkey.example.com'] = ['v=DKIM1; p=b3RoZXI='];
        $secret = $this->storeToken($this->findUser());
        $sessionId = $this->initialiseSession($secret);

        foreach (['dkim_status', 'dkim_disable', 'dkim_enable'] as $tool) {
            $this->assertFreeOfPrivateKey($this->rawCall($secret, $sessionId, $tool, self::READ_ARGUMENTS), $tool);
        }

        $this->assertFreeOfPrivateKey($this->rawCall($secret, $sessionId, 'dkim_recreate_key', self::RECREATE_ARGUMENTS), 'dkim_recreate_key');
        $this->assertFreeOfPrivateKey($this->rawCall($secret, $sessionId, 'dkim_recreate_key', ['domain' => 'example.com', 'confirm' => false]), 'unconfirmed');
        $this->assertFreeOfPrivateKey($this->auditRecordsAsJson(), 'audit log');
    }

    public function testUnreadableKeyDoesNotAppearInAnError(): void
    {
        $domain = $this->reloadDomain();
        $domain->setDkimPrivateKey('-----BEGIN PRIVATE KEY-----sentinelkeymaterial-----END PRIVATE KEY-----');
        $this->entityManager()->flush();

        $secret = $this->storeToken($this->findUser());
        $sessionId = $this->initialiseSession($secret);

        $output = $this->rawCall($secret, $sessionId, 'dkim_status', self::READ_ARGUMENTS);

        self::assertStringNotContainsString('sentinelkeymaterial', $output);
        self::assertStringNotContainsString('PRIVATE KEY', $output);
    }

    public function testStatusSaysSoWhenTheDnsLookupRanOutOfTime(): void
    {
        FakeDnsLookup::$outOfTimeAt = ['dkim._domainkey.example.com'];

        $status = $this->adminCall('dkim_status', self::READ_ARGUMENTS);

        self::assertTrue($status['timed_out']);
        self::assertNull($status['record_found']);
        self::assertNull($status['record_valid']);
        self::assertNotSame('', $status['expected_record']);
    }

    public function testAChangeIsKeptWhenTheDnsLookupAfterItRanOutOfTime(): void
    {
        FakeDnsLookup::$outOfTimeAt = ['dkim._domainkey.example.com'];

        $status = $this->adminCall('dkim_disable', self::READ_ARGUMENTS);

        self::assertTrue($status['timed_out']);
        self::assertFalse($status['enabled']);
    }

    public function testCallsAreAuditedWithTheDomain(): void
    {
        $this->adminCall('dkim_disable', self::READ_ARGUMENTS);

        $records = $this->auditHandler()->getRecords();
        $record = end($records);
        self::assertNotFalse($record);
        self::assertSame('dkim_disable', $record->context['tool']);
        self::assertSame('domain:example.com', $record->context['target']);
        self::assertSame('ok', $record->context['result']);
    }

    private function domainAdmin(): User
    {
        $user = $this->findUser();
        $user->setAdmin(false);
        $user->setDomainAdmin(true);

        return $user;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertDeniedFor(User $user, string $tool, array $arguments, ApiTokenScope $scope): void
    {
        $before = $this->privateKey();
        $secret = $this->storeToken($user, [$scope]);
        $sessionId = $this->initialiseSession($secret);

        self::assertNotContains($tool, $this->listTools($secret, $sessionId));
        $this->assertDeniedMessage($this->callTool($secret, $sessionId, $tool, $arguments));
        self::assertSame($before, $this->privateKey());
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assertDeniedMessage(array $result): void
    {
        self::assertTrue($result['isError']);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
    }

    private function assertFreeOfPrivateKey(string $output, string $context): void
    {
        self::assertStringNotContainsString('PRIVATE KEY', $output, $context);
        self::assertStringNotContainsString($this->keyBody(), $output, $context);
    }

    /**
     * Lines of the stored key, each long enough not to appear by chance.
     */
    private function keyBody(): string
    {
        return explode("\n", $this->privateKey())[1];
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> the decoded tool output
     */
    private function adminCall(string $tool, array $arguments): array
    {
        $secret = $this->storeToken($this->findUser());
        $result = $this->callTool($secret, $this->initialiseSession($secret), $tool, $arguments);

        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');

        return json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function rawCall(string $secret, string $sessionId, string $tool, array $arguments): string
    {
        $response = $this->mcpRequest('tools/call', ['name' => $tool, 'arguments' => $arguments], 'Bearer ' . $secret, $sessionId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return (string) $response->getContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function describeTool(string $name): array
    {
        $secret = $this->storeToken($this->findUser());
        $sessionId = $this->initialiseSession($secret);
        $response = $this->mcpRequest('tools/list', [], 'Bearer ' . $secret, $sessionId);
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($body['result']['tools'] as $tool) {
            if ($name === $tool['name']) {
                return $tool;
            }
        }

        self::fail(\sprintf('Tool "%s" is not listed.', $name));
    }

    private function expectedRecord(): string
    {
        $container = static::getContainer();

        return $container->get(FormatterService::class)->getTXTRecord(
            $container->get(KeyGenerationService::class)->extractPublicKey($this->privateKey()),
            KeyGenerationService::DIGEST_ALGORITHM,
        );
    }

    private function storeKeylessDisabledDomain(): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE mail_domains SET dkim_private_key = :key, dkim_enabled = :enabled WHERE name = :name',
            ['key' => '', 'enabled' => 0, 'name' => 'example.com'],
            ['enabled' => 'boolean'],
        );
        $this->entityManager()->clear();
    }

    private function setDkimEnabled(bool $enabled): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE mail_domains SET dkim_enabled = :enabled WHERE name = :name',
            ['enabled' => $enabled, 'name' => 'example.com'],
            ['enabled' => 'boolean'],
        );
        $this->entityManager()->clear();
    }

    private function privateKey(): string
    {
        $key = $this->entityManager()->getConnection()->fetchOne('SELECT dkim_private_key FROM mail_domains WHERE name = :name', ['name' => 'example.com']);
        self::assertIsString($key);

        return $key;
    }

    private function reloadDomain(): Domain
    {
        $this->entityManager()->clear();
        $domain = $this->entityManager()->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        self::assertInstanceOf(Domain::class, $domain);

        return $domain;
    }

    private function findUserByEntity(Domain $domain): User
    {
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['domain' => $domain, 'name' => 'admin']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function redis(): Client
    {
        return new Client([
            'host' => $_SERVER['REDIS_HOST'] ?? $_ENV['REDIS_HOST'],
            'port' => $_SERVER['REDIS_PORT'] ?? $_ENV['REDIS_PORT'],
            'password' => ($_SERVER['REDIS_PASSWORD'] ?? $_ENV['REDIS_PASSWORD'] ?? '') ?: null,
        ]);
    }

    private function auditRecordsAsJson(): string
    {
        return json_encode(array_map(static fn ($record): array => $record->context, $this->auditHandler()->getRecords()), \JSON_THROW_ON_ERROR);
    }

    private function auditHandler(): TestHandler
    {
        $handler = $this->client->getContainer()->get('app.test.mcp_audit_records');
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }
}
