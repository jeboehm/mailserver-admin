<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Helper;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Speaks the MCP protocol to /mcp through the test client.
 *
 * The client reboots the kernel between requests, so an MCP session only survives
 * if its store is shared. That makes every multi-request test a small two-replica test.
 */
trait McpClientTrait
{
    private KernelBrowser $client;

    private function enableMcp(): void
    {
        $this->setEnvironment('MCP_ENABLED', 'true');
    }

    private function setEnvironment(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function unsetEnvironment(string $name): void
    {
        unset($_ENV[$name], $_SERVER[$name]);
    }

    private function findUser(string $email = 'admin@example.com'): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmailAddress($email);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Stores a token directly, so tests can also create tokens the issuer would refuse.
     *
     * @param list<ApiTokenScope> $scopes
     */
    private function storeToken(User $owner, array $scopes = ApiTokenScope::ALL, string $expiry = '+1 day', ?string $secret = null): string
    {
        $secret ??= 'test_' . bin2hex(random_bytes(16));

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ApiToken($owner, 'test token', ApiToken::hashSecret($secret), $scopes, new \DateTimeImmutable($expiry)));
        $entityManager->flush();

        return $secret;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function mcpRequest(string $method, array $params = [], ?string $authorization = null, ?string $sessionId = null, string $host = 'localhost'): Response
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_HOST' => $host,
        ];

        if (null !== $authorization) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }

        if (null !== $sessionId) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->client->request(Request::METHOD_POST, '/mcp', server: $server, content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => [] === $params ? new \stdClass() : $params,
        ], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function initialiseSession(string $secret): string
    {
        $response = $this->mcpRequest('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ], 'Bearer ' . $secret);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $sessionId = $response->headers->get('Mcp-Session-Id');
        self::assertNotNull($sessionId);

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>} the JSON-RPC result
     */
    private function callTool(string $secret, string $sessionId, string $tool, array $arguments = []): array
    {
        $response = $this->mcpRequest('tools/call', [
            'name' => $tool,
            'arguments' => [] === $arguments ? new \stdClass() : $arguments,
        ], 'Bearer ' . $secret, $sessionId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('result', $body, (string) $response->getContent());
        self::assertIsArray($body['result']);

        /** @var array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>} $result */
        $result = $body['result'];

        return $result;
    }

    /**
     * Sends the calls as one JSON-RPC batch: the SDK handles them in one PHP request, so they share
     * one entity manager.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $calls tool name and arguments
     *
     * @return list<array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>}> the JSON-RPC results, in the order of the calls
     */
    private function callToolsInBatch(string $secret, string $sessionId, array $calls): array
    {
        $messages = [];

        foreach ($calls as $index => [$tool, $arguments]) {
            $messages[] = [
                'jsonrpc' => '2.0',
                'id' => $index + 1,
                'method' => 'tools/call',
                'params' => ['name' => $tool, 'arguments' => [] === $arguments ? new \stdClass() : $arguments],
            ];
        }

        $this->client->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $secret,
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ], content: json_encode($messages, \JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $answers = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answers);

        $results = array_column($answers, 'result', 'id');
        ksort($results);
        self::assertCount(\count($calls), $results, (string) $response->getContent());

        /** @var list<array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>}> $ordered */
        $ordered = array_values($results);

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function listTools(string $secret, string $sessionId): array
    {
        return array_column($this->toolCatalogue($secret, $sessionId), 'name');
    }

    /**
     * @return list<array{name: string, description?: string, annotations?: array{readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool}, inputSchema: array{properties?: array<string, array{type?: string, description?: string, items?: array{type?: string}}>, required?: list<string>}}>
     */
    private function toolCatalogue(string $secret, string $sessionId): array
    {
        $response = $this->mcpRequest('tools/list', [], 'Bearer ' . $secret, $sessionId);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{result: array{tools: list<array{name: string, description?: string, annotations?: array{readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool}, inputSchema: array{properties?: array<string, array{type?: string, description?: string, items?: array{type?: string}}>, required?: list<string>}}>}} $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['result']['tools'];
    }

    /**
     * @return array{name: string, description?: string, annotations?: array{readOnlyHint?: bool, destructiveHint?: bool, idempotentHint?: bool, openWorldHint?: bool}, inputSchema: array{properties?: array<string, array{type?: string, description?: string, items?: array{type?: string}}>, required?: list<string>}}
     */
    private function toolDefinition(string $secret, string $sessionId, string $name): array
    {
        foreach ($this->toolCatalogue($secret, $sessionId) as $tool) {
            if ($name === $tool['name']) {
                return $tool;
            }
        }

        self::fail(\sprintf('Tool "%s" is not listed.', $name));
    }

    /**
     * @param array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>} $result
     *
     * @return array<string, mixed> the structured content of a successful call
     */
    private function assertToolSucceeded(array $result): array
    {
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));
        self::assertArrayHasKey('structuredContent', $result, (string) json_encode($result));

        return $result['structuredContent'];
    }

    /**
     * @param array{content: list<array{type: string, text: string}>, isError?: bool, structuredContent?: array<string, mixed>} $result
     *
     * @return array<string, mixed> the JSON text of a successful call, decoded
     */
    private function toolOutput(array $result): array
    {
        self::assertFalse($result['isError'] ?? false, (string) json_encode($result));

        $output = json_decode($result['content'][0]['text'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($output);

        /** @var array<string, mixed> $decoded */
        $decoded = $output;

        return $decoded;
    }

    private function shortLivedCredentialCount(): int
    {
        $count = static::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM mail_mailbox_credentials WHERE kind = 'short_lived'");
        self::assertIsNumeric($count);

        return (int) $count;
    }
}
