<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\McpClientTrait;

class ApiTokenRevocationTest extends WebTestCase
{
    use McpClientTrait;

    private const string OTHER_USER = 'tokenowner@example.com';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();

        $user = new User();
        $user->setName('tokenowner');
        $user->setDomain($this->findUser()->getDomain());
        $user->setPassword('initial-hash');
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');

        parent::tearDown();
    }

    public function testUnrelatedChangeKeepsTokensValid(): void
    {
        $secret = $this->storeToken($this->findUser(self::OTHER_USER));

        $this->findUser(self::OTHER_USER)->setQuota(5);
        $this->entityManager()->flush();

        $this->assertTokenWorks($secret);
    }

    public function testDisablingTheOwnerRevokesTokensForGood(): void
    {
        $secret = $this->storeToken($this->findUser(self::OTHER_USER));

        $this->findUser(self::OTHER_USER)->setEnabled(false);
        $this->entityManager()->flush();
        $this->findUser(self::OTHER_USER)->setEnabled(true);
        $this->entityManager()->flush();

        $this->assertTokenRejected($secret);
    }

    public function testChangingThePasswordRevokesTokens(): void
    {
        $secret = $this->storeToken($this->findUser(self::OTHER_USER));

        // The way both password forms trigger the hashing listener.
        $user = $this->findUser(self::OTHER_USER);
        $user->setPlainPassword('a-new-Passw0rd!');
        $user->setPassword('');
        $this->entityManager()->flush();

        $this->assertTokenRejected($secret);
    }

    public function testTokensOfOtherUsersStayValid(): void
    {
        $bystander = $this->storeToken($this->findUser());
        $this->storeToken($this->findUser(self::OTHER_USER));

        $this->findUser(self::OTHER_USER)->setEnabled(false);
        $this->entityManager()->flush();

        $this->assertTokenWorks($bystander);
    }

    public function testTokenCreatedAfterRevocationWorks(): void
    {
        $this->storeToken($this->findUser(self::OTHER_USER));
        $this->findUser(self::OTHER_USER)->setEnabled(false);
        $this->entityManager()->flush();
        $this->findUser(self::OTHER_USER)->setEnabled(true);
        $this->entityManager()->flush();

        $this->assertTokenWorks($this->storeToken($this->findUser(self::OTHER_USER)));
    }

    public function testDeletingTheOwnerRemovesTheirTokens(): void
    {
        $secret = $this->storeToken($this->findUser(self::OTHER_USER));

        $this->entityManager()->remove($this->findUser(self::OTHER_USER));
        $this->entityManager()->flush();

        $this->assertTokenRejected($secret);
    }

    public function testTokensRevokedByALocalDisableStayRevokedAfterAnOidcLoginReEnablesTheUser(): void
    {
        $secret = $this->storeToken($this->findUser());

        $this->findUser()->setEnabled(false);
        $this->entityManager()->flush();

        // The mock is set on this kernel and the CSRF state lives in the session, so keep both across requests.
        $this->client->disableReboot();
        self::getContainer()->set('hwi_oauth.http_client', new MockHttpClient([
            new JsonMockResponse(['access_token' => 'access-token', 'token_type' => 'bearer']),
            new JsonMockResponse(['sub' => 'admin@example.com', 'groups' => ['admin']]),
        ]));

        $this->client->request(Request::METHOD_GET, '/connect/default');
        self::assertResponseRedirects();
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $authorisationRequest);

        $this->client->request(Request::METHOD_GET, '/login/check-oauth', [
            'code' => 'authorisation-code',
            'state' => $authorisationRequest['state'],
        ]);
        self::assertResponseRedirects('http://localhost/');

        $this->entityManager()->clear();
        self::assertTrue($this->findUser()->getEnabled(), 'The OIDC login must re-enable the user.');

        $this->client->getCookieJar()->clear();
        $this->assertTokenRejected($secret);
    }

    private function assertTokenWorks(string $secret): void
    {
        $this->initialiseSession($secret);
    }

    private function assertTokenRejected(string $secret): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->mcpRequest('ping', [], 'Bearer ' . $secret)->getStatusCode());
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
