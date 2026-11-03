<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\McpClientTrait;

class McpAuthenticationTest extends WebTestCase
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

    public function testValidTokenCanCallWhoami(): void
    {
        $secret = $this->storeToken($this->findUser(), [ApiTokenScope::ManageRead, ApiTokenScope::MailRead]);
        $sessionId = $this->initialiseSession($secret);

        $result = $this->callTool($secret, $sessionId, 'whoami');

        self::assertSame([
            'user' => 'admin@example.com',
            'role' => 'admin',
            'domain' => 'example.com',
            'scopes' => ['manage:read', 'mail:read'],
        ], $this->assertToolSucceeded($result));
    }

    public function testRequestStartsNoWebSession(): void
    {
        $secret = $this->storeToken($this->findUser());

        $response = $this->mcpRequest('ping', [], 'Bearer ' . $secret);

        self::assertSame([], $response->headers->getCookies());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedAuthorizationHeaders(): iterable
    {
        yield 'unknown token' => ['Bearer does-not-exist'];
        yield 'characters a bearer token cannot have' => ['Bearer not a token!'];
        yield 'empty bearer' => ['Bearer '];
        yield 'other scheme' => ['Basic YWRtaW46Y2hhbmdlbWU='];
    }

    #[DataProvider('rejectedAuthorizationHeaders')]
    public function testMalformedOrUnknownTokenGets401(string $authorization): void
    {
        $response = $this->mcpRequest('ping', [], $authorization);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function testMissingTokenGets401WithChallenge(): void
    {
        $response = $this->mcpRequest('ping');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertStringStartsWith('Bearer', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function testExpiredTokenGets401(): void
    {
        $secret = $this->storeToken($this->findUser(), expiry: '-1 second');

        $response = $this->mcpRequest('ping', [], 'Bearer ' . $secret);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testRevokedTokenGets401(): void
    {
        $owner = $this->findUser();
        $secret = $this->storeToken($owner);
        $token = $this->findToken($secret);
        $token->revoke();
        $this->flush();

        $response = $this->mcpRequest('ping', [], 'Bearer ' . $secret);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testDisabledOwnerGets401InAnOpenSession(): void
    {
        $owner = $this->findUser();
        $secret = $this->storeToken($owner);
        $sessionId = $this->initialiseSession($secret);

        $this->findUser()->setEnabled(false);
        $this->flush();

        $response = $this->mcpRequest('tools/call', ['name' => 'whoami'], 'Bearer ' . $secret, $sessionId);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testDisabledOwnerGets401EvenIfTheTokenWasNotRevoked(): void
    {
        $owner = $this->findUser();
        $secret = $this->storeToken($owner);

        $this->disableWithoutRevoking((int) $owner->getId());

        self::assertFalse($this->findToken($secret)->isRevoked());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->mcpRequest('ping', [], 'Bearer ' . $secret)->getStatusCode());
    }

    public function testRoleChangeTakesEffectOnTheNextRequest(): void
    {
        $secret = $this->storeToken($this->findUser());
        $sessionId = $this->initialiseSession($secret);

        self::assertSame('admin', $this->assertToolSucceeded($this->callTool($secret, $sessionId, 'whoami'))['role']);

        $user = $this->findUser();
        $user->setAdmin(false);
        $user->setDomainAdmin(true);
        $this->flush();

        self::assertSame('domain_admin', $this->assertToolSucceeded($this->callTool($secret, $sessionId, 'whoami'))['role']);

        $user = $this->findUser();
        $user->setDomainAdmin(false);
        $this->flush();

        self::assertSame('user', $this->assertToolSucceeded($this->callTool($secret, $sessionId, 'whoami'))['role']);
    }

    public function testSuccessfulRequestRecordsLastUse(): void
    {
        $secret = $this->storeToken($this->findUser());
        self::assertNull($this->findToken($secret)->getLastUsedAt());

        $this->mcpRequest('ping', [], 'Bearer ' . $secret);

        $this->entityManager()->clear();
        self::assertNotNull($this->findToken($secret)->getLastUsedAt());
    }

    public function testRejectedRequestDoesNotRecordLastUse(): void
    {
        $user = $this->findUser();
        $secret = $this->storeToken($user);
        $this->disableWithoutRevoking((int) $user->getId());

        $this->mcpRequest('ping', [], 'Bearer ' . $secret);

        $this->entityManager()->clear();
        self::assertNull($this->findToken($secret)->getLastUsedAt());
    }

    /**
     * Goes around Doctrine, like a change made directly in the database.
     */
    private function disableWithoutRevoking(int $userId): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE mail_users SET enabled = ? WHERE id = ?',
            [false, $userId],
            [ParameterType::BOOLEAN, ParameterType::INTEGER],
        );
        $this->entityManager()->clear();
    }

    private function findToken(string $secret): ApiToken
    {
        $token = static::getContainer()->get(ApiTokenRepository::class)->findOneBy(['secretHash' => ApiToken::hashSecret($secret)]);
        self::assertInstanceOf(ApiToken::class, $token);

        return $token;
    }

    private function flush(): void
    {
        $this->entityManager()->flush();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
