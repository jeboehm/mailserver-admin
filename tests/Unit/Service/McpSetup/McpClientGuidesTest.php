<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\McpSetup;

use App\Service\McpSetup\McpClientGuide;
use App\Service\McpSetup\McpClientGuides;
use PHPUnit\Framework\TestCase;

class McpClientGuidesTest extends TestCase
{
    private const string URL = 'https://mail.example.com/mcp';

    public function testClaudeCodeReadsTheTokenFromTheEnvironment(): void
    {
        /** @var array{mcpServers: array{'mailserver-admin': array{type: string, url: string, headers: array{Authorization: string}}}} $config */
        $config = $this->jsonOf('Claude Code');
        $server = $config['mcpServers']['mailserver-admin'];

        self::assertSame('http', $server['type']);
        self::assertSame(self::URL, $server['url']);
        self::assertSame('Bearer ${MAILSERVER_MCP_TOKEN}', $server['headers']['Authorization']);
    }

    public function testCursorReadsTheTokenFromTheEnvironment(): void
    {
        /** @var array{mcpServers: array{'mailserver-admin': array{url: string, headers: array{Authorization: string}}}} $config */
        $config = $this->jsonOf('Cursor');
        $server = $config['mcpServers']['mailserver-admin'];

        self::assertSame(self::URL, $server['url']);
        self::assertSame('Bearer ${env:MAILSERVER_MCP_TOKEN}', $server['headers']['Authorization']);
    }

    public function testVsCodePromptsForTheTokenAsAPassword(): void
    {
        /** @var array{inputs: list<array{password: bool, id: string}>, servers: array{'mailserver-admin': array{type: string, url: string, headers: array{Authorization: string}}}} $config */
        $config = $this->jsonOf('VS Code');
        $input = $config['inputs'][0];
        $server = $config['servers']['mailserver-admin'];

        self::assertTrue($input['password']);
        self::assertSame('http', $server['type']);
        self::assertSame(self::URL, $server['url']);
        self::assertSame(\sprintf('Bearer ${input:%s}', $input['id']), $server['headers']['Authorization']);
    }

    public function testCodexNamesTheVariableInsteadOfTheToken(): void
    {
        self::assertSame(
            "[mcp_servers.mailserver-admin]\nurl = \"https://mail.example.com/mcp\"\nbearer_token_env_var = \"MAILSERVER_MCP_TOKEN\"",
            $this->guide('Codex CLI')->snippet,
        );
    }

    public function testClaudeDesktopConnectsThroughTheBridge(): void
    {
        /** @var array{mcpServers: array{'mailserver-admin': array{command: string, args: list<string>, env: array{AUTH_HEADER: string}}}} $config */
        $config = $this->jsonOf('Claude Desktop');
        $server = $config['mcpServers']['mailserver-admin'];

        self::assertSame('npx', $server['command']);
        self::assertSame(['-y', 'mcp-remote', self::URL, '--header', 'Authorization:${AUTH_HEADER}'], $server['args']);
        self::assertSame('Bearer <YOUR_API_TOKEN>', $server['env']['AUTH_HEADER']);
    }

    private function jsonOf(string $client): mixed
    {
        return json_decode($this->guide($client)->snippet, true, flags: \JSON_THROW_ON_ERROR);
    }

    private function guide(string $client): McpClientGuide
    {
        foreach (new McpClientGuides()->forEndpoint(self::URL) as $guide) {
            if ($client === $guide->name) {
                return $guide;
            }
        }

        self::fail('No guide for ' . $client);
    }
}
