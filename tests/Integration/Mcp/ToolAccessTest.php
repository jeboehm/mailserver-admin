<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Mcp\ToolDeniedException;
use App\Service\ApiToken\ApiTokenScope;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\Helper\McpClientTrait;

/**
 * The gate and the list handler as wired by the container, over HTTP.
 */
class ToolAccessTest extends WebTestCase
{
    use McpClientTrait;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');

        parent::tearDown();
    }

    public function testListShowsToolsTheTokenMayUse(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailSend]);

        $tools = $this->listTools($secret, $this->initialiseSession($secret));

        self::assertContains('whoami', $tools);
        self::assertContains('fixture_admin_send', $tools);
    }

    public function testListHidesToolsOutsideTheScopesOfTheToken(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageRead]);

        $tools = $this->listTools($secret, $this->initialiseSession($secret));

        self::assertContains('whoami', $tools);
        self::assertNotContains('fixture_admin_send', $tools);
    }

    public function testListHidesToolsAboveTheRoleOfTheOwner(): void
    {
        $user = $this->findUser();
        $user->setAdmin(false);
        $secret = $this->storeToken($user, [ApiTokenScope::MailSend]);

        self::assertNotContains('fixture_admin_send', $this->listTools($secret, $this->initialiseSession($secret)));
    }

    public function testCallingAHiddenToolIsRefusedWithTheSharedMessage(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageRead]);

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'fixture_admin_send');

        self::assertTrue($result['isError']);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
    }

    public function testCallingAPermittedToolRuns(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::MailSend]);

        $result = $this->callTool($secret, $this->initialiseSession($secret), 'fixture_admin_send');

        self::assertFalse($result['isError'] ?? false);
        self::assertSame('ran', $result['content'][0]['text']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protocolVersions(): iterable
    {
        yield 'oldest' => ['2024-11-05'];
        yield 'newest handshake era' => ['2025-11-25'];
    }

    #[DataProvider('protocolVersions')]
    public function testHandshakeEraRevisionsAreGated(string $version): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageRead]);
        $response = $this->mcpRequest('initialize', [
            'protocolVersion' => $version,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ], 'Bearer ' . $secret);
        $sessionId = (string) $response->headers->get('Mcp-Session-Id');

        self::assertTrue($this->callTool($secret, $sessionId, 'fixture_admin_send')['isError']);
    }

    public function testModernEraCallsAreGatedAndListedTheSameWay(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageRead]);

        $tools = $this->modernRequest($secret, 'tools/list', [], 'tools/list');
        self::assertContains('whoami', array_column($tools['result']['tools'], 'name'));
        self::assertNotContains('fixture_admin_send', array_column($tools['result']['tools'], 'name'));

        $call = $this->modernRequest($secret, 'tools/call', ['name' => 'fixture_admin_send', 'arguments' => new \stdClass()], 'tools/call', 'fixture_admin_send');
        self::assertTrue($call['result']['isError']);
        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $call['result']['content'][0]['text']);
        self::assertSame('denied', $this->lastAuditRecord()->context['result']);
    }

    public function testSubscriptionStreamDoesNotHoldAPhpThreadForLong(): void
    {
        $secret = $this->storeToken($this->findUser());
        $startedAt = microtime(true);

        $this->client->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_HOST' => 'localhost',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => 'subscriptions/listen',
        ], content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'subscriptions/listen',
            'params' => ['notifications' => new \stdClass(), '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1.0'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertLessThan(5.0, microtime(true) - $startedAt);
    }

    public function testSuccessfulCallIsAudited(): void
    {
        $owner = $this->findUser();
        $secret = $this->storeToken($owner);
        $sessionId = $this->initialiseSession($secret);

        $this->callTool($secret, $sessionId, 'whoami');

        $record = $this->lastAuditRecord();
        self::assertSame('whoami', $record->context['tool']);
        self::assertSame('ok', $record->context['result']);
        self::assertSame('admin@example.com', $record->context['user']);
        self::assertIsInt($record->context['token_id']);
    }

    public function testDeniedCallIsAuditedWithItsTargetAndWithoutItsArguments(): void
    {
        $secret = $this->storeToken($this->findUser());
        $sessionId = $this->initialiseSession($secret);

        $result = $this->callTool($secret, $sessionId, 'fixture_touch_foreign', ['password' => 'sentinel-password']);

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        $record = $this->lastAuditRecord();
        self::assertSame('denied', $record->context['result']);
        self::assertSame('user:12', $record->context['target']);
        self::assertStringNotContainsString('sentinel-password', (string) json_encode($record->context));
        self::assertStringNotContainsString($secret, (string) json_encode($record->context));
    }

    public function testCallWithoutTheScopeIsAuditedWithTheTargetItAskedFor(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageRead]);
        $sessionId = $this->initialiseSession($secret);

        $result = $this->callTool($secret, $sessionId, 'user_update', ['id' => 12, 'password' => 'sentinel-password']);

        self::assertSame(ToolDeniedException::PUBLIC_MESSAGE, $result['content'][0]['text']);
        $record = $this->lastAuditRecord();
        self::assertSame('user_update', $record->context['tool']);
        self::assertSame('denied', $record->context['result']);
        self::assertSame('user:12', $record->context['target']);
        self::assertStringNotContainsString('sentinel-password', (string) json_encode($record->context));
    }

    /**
     * The 2026-07-28 revision has no handshake: every request carries its own metadata.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function modernRequest(string $secret, string $method, array $params, string $methodHeader, ?string $nameHeader = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_HOST' => 'localhost',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => $methodHeader,
        ];

        if (null !== $nameHeader) {
            $server['HTTP_MCP_NAME'] = $nameHeader;
        }

        $this->client->request(Request::METHOD_POST, '/mcp', server: $server, content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => [...$params, '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1.0'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ], \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function lastAuditRecord(): LogRecord
    {
        $handler = $this->client->getContainer()->get('app.test.mcp_audit_records');
        self::assertInstanceOf(TestHandler::class, $handler);

        $records = $handler->getRecords();
        self::assertNotEmpty($records);

        return end($records);
    }
}
