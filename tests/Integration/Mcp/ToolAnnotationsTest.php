<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Integration\Helper\McpClientTrait;
use Tests\Integration\Helper\UserTrait;

class ToolAnnotationsTest extends WebTestCase
{
    use McpClientTrait;
    use UserTrait;

    /**
     * The tools that query public DNS, read mail written by others or send mail to others.
     */
    private const array OPEN_WORLD_TOOLS = [
        'dkim_disable',
        'dkim_enable',
        'dkim_recreate_key',
        'dkim_status',
        'dns_validate',
        'message_get',
        'message_search',
        'message_send',
    ];

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');
        $this->unsetEnvironment('MCP_MAIL_READ_ENABLED');
        $this->unsetEnvironment('MCP_MAIL_SEND_ENABLED');

        parent::tearDown();
    }

    /**
     * MCP clients treat a missing openWorldHint as true, so a tool has to state it to count as closed.
     */
    public function testEveryToolDeclaresWhetherItsWorldIsOpen(): void
    {
        $this->enableMcp();
        $this->setEnvironment('MCP_MAIL_READ_ENABLED', 'true');
        $this->setEnvironment('MCP_MAIL_SEND_ENABLED', 'true');
        $this->client = static::createClient();

        $secret = $this->storeToken($this->findUser());
        $sessionId = $this->initialiseSession($secret);

        $hints = [];

        foreach ($this->toolCatalogue($secret, $sessionId) as $tool) {
            // The test environment adds its own tools, which no client ever sees.
            if (!str_starts_with($tool['name'], 'fixture_')) {
                $hints[$tool['name']] = $tool['annotations']['openWorldHint'] ?? null;
            }
        }

        self::assertSame([], array_keys($hints, null, true), 'These tools do not declare openWorldHint.');

        $openWorldTools = array_keys($hints, true, true);
        sort($openWorldTools);

        self::assertSame(self::OPEN_WORLD_TOOLS, $openWorldTools);
    }
}
