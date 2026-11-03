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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\McpClientTrait;
use Tests\Integration\Helper\UserTrait;

class McpAvailabilityTest extends WebTestCase
{
    use McpClientTrait;
    use UserTrait;

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');
        $this->unsetEnvironment('MCP_ALLOWED_HOSTS');

        parent::tearDown();
    }

    public function testEndpointIsOffByDefault(): void
    {
        $this->client = static::createClient();
        $secret = $this->storeToken($this->findUser());

        self::assertSame(Response::HTTP_NOT_FOUND, $this->mcpRequest('ping')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->mcpRequest('ping', [], 'Bearer ' . $secret)->getStatusCode());
    }

    public function testExplicitlyDisabledEndpointAnswersLikeAnUnknownPage(): void
    {
        $this->setEnvironment('MCP_ENABLED', 'false');
        $this->client = static::createClient();

        $endpoint = $this->mcpRequest('ping')->getStatusCode();
        $this->client->request(Request::METHOD_POST, '/no-such-page');

        self::assertSame($this->client->getResponse()->getStatusCode(), $endpoint);
    }

    public function testDisabledEndpointIgnoresAWebSession(): void
    {
        $this->client = static::createClient();
        $this->loginClient($this->client);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->mcpRequest('ping')->getStatusCode());
    }

    public function testEnabledEndpointRequiresAToken(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->mcpRequest('ping')->getStatusCode());
    }

    public function testWebSessionIsNoCredentialForTheEndpoint(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();
        $this->loginClient($this->client);

        // loginUser() sets the token for the next request directly. From then on, only the session cookie is left.
        $this->client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->mcpRequest('ping')->getStatusCode());
    }

    public function testForeignHostIsRefusedByDefault(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();
        $secret = $this->storeToken($this->findUser());

        $response = $this->mcpRequest('ping', [], 'Bearer ' . $secret, host: 'mail.example.com');

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testConfiguredHostIsAccepted(): void
    {
        $this->enableMcp();
        $this->setEnvironment('MCP_ALLOWED_HOSTS', 'mail.example.com,mcp.example.com');
        $this->client = static::createClient();
        $secret = $this->storeToken($this->findUser());

        $response = $this->mcpRequest('initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ], 'Bearer ' . $secret, host: 'mcp.example.com');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testSessionWrittenByOneKernelIsFoundByTheNext(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();
        $secret = $this->storeToken($this->findUser());

        $sessionId = $this->initialiseSession($secret);
        $this->client->getKernel()->shutdown();

        self::assertContains('whoami', $this->listTools($secret, $sessionId));
    }
}
