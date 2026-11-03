<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\McpSetup;

/**
 * The configuration each MCP client needs for this server. The endpoint only takes a bearer token, so
 * only clients with a documented way to send one are listed. Install deep links are left out on purpose:
 * no client documents them for a remote server with a secret header, and a real token must never go in a URL.
 */
final readonly class McpClientGuides
{
    public const string TOKEN_VARIABLE = 'MAILSERVER_MCP_TOKEN';

    private const string SERVER_NAME = 'mailserver-admin';

    /**
     * @return list<McpClientGuide>
     */
    public function forEndpoint(string $url): array
    {
        return [
            new McpClientGuide(
                'Claude Code',
                '.mcp.json',
                $this->json(['mcpServers' => [self::SERVER_NAME => [
                    'type' => 'http',
                    'url' => $url,
                    'headers' => ['Authorization' => \sprintf('Bearer ${%s}', self::TOKEN_VARIABLE)],
                ]]]),
                'https://code.claude.com/docs/en/mcp',
                \sprintf('Reads the token from %s when it connects.', self::TOKEN_VARIABLE),
            ),
            new McpClientGuide(
                'Cursor',
                '~/.cursor/mcp.json',
                $this->json(['mcpServers' => [self::SERVER_NAME => [
                    'url' => $url,
                    'headers' => ['Authorization' => \sprintf('Bearer ${env:%s}', self::TOKEN_VARIABLE)],
                ]]]),
                'https://cursor.com/docs/context/mcp',
                \sprintf('Reads the token from %s, so Cursor has to be started from an environment that has it.', self::TOKEN_VARIABLE),
            ),
            new McpClientGuide(
                'VS Code',
                '.vscode/mcp.json',
                $this->json([
                    'inputs' => [[
                        'type' => 'promptString',
                        'id' => 'mailserver-mcp-token',
                        'description' => 'mailserver-admin API token',
                        'password' => true,
                    ]],
                    'servers' => [self::SERVER_NAME => [
                        'type' => 'http',
                        'url' => $url,
                        'headers' => ['Authorization' => 'Bearer ${input:mailserver-mcp-token}'],
                    ]],
                ]),
                'https://code.visualstudio.com/docs/agents/reference/mcp-configuration',
                'Asks for the token when the server first starts, so no environment variable is needed.',
            ),
            new McpClientGuide(
                'Codex CLI',
                '~/.codex/config.toml',
                implode("\n", [
                    \sprintf('[mcp_servers.%s]', self::SERVER_NAME),
                    \sprintf('url = %s', $this->json($url)),
                    \sprintf('bearer_token_env_var = "%s"', self::TOKEN_VARIABLE),
                ]),
                'https://learn.chatgpt.com/docs/extend/mcp?surface=cli',
                \sprintf('Reads the token from %s. The setting takes the name of the variable, not the token.', self::TOKEN_VARIABLE),
            ),
            new McpClientGuide(
                'Claude Desktop',
                'claude_desktop_config.json',
                $this->json(['mcpServers' => [self::SERVER_NAME => [
                    'command' => 'npx',
                    'args' => ['-y', 'mcp-remote', $url, '--header', 'Authorization:${AUTH_HEADER}'],
                    'env' => ['AUTH_HEADER' => 'Bearer <YOUR_API_TOKEN>'],
                ]]]),
                'https://github.com/geelen/mcp-remote',
                'Connects through mcp-remote, a third-party bridge that needs Node.js. The token is stored unencrypted in this file. Without HTTPS, the bridge also needs the --allow-http argument; only use that on a trusted private network.',
            ),
        ];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }
}
