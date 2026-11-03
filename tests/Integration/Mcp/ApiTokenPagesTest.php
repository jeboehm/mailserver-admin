<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Controller\User\ApiTokenCrudController;
use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ApiToken\ApiTokenScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\CsrfTokenTrait;
use Tests\Integration\Helper\McpClientTrait;

class ApiTokenPagesTest extends WebTestCase
{
    use CsrfTokenTrait;
    use McpClientTrait;

    private const string ADMIN = 'admin@example.com';
    private const string DOMAIN_ADMIN = 'boss@example.com';
    private const string REGULAR_USER = 'plainuser@example.com';
    private const string NEIGHBOUR = 'neighbour@example.com';
    private const string FOREIGN_USER = 'foreignuser@foreign.invalid';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMcp();
        $this->client = static::createClient();

        $entityManager = $this->entityManager();
        $ownDomain = $entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        self::assertInstanceOf(Domain::class, $ownDomain);

        $foreignDomain = new Domain();
        $foreignDomain->setName('foreign.invalid');
        $entityManager->persist($foreignDomain);

        $this->createUser($ownDomain, 'boss')->setDomainAdmin(true);
        $this->createUser($ownDomain, 'plainuser');
        $this->createUser($ownDomain, 'neighbour');
        $this->createUser($foreignDomain, 'foreignuser');
        $entityManager->flush();

        foreach ([self::ADMIN, self::DOMAIN_ADMIN, self::REGULAR_USER, self::NEIGHBOUR, self::FOREIGN_USER] as $email) {
            $this->storeNamedToken($email);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->unsetEnvironment('MCP_ENABLED');

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function visibleTokens(): iterable
    {
        yield 'admin sees all' => [self::ADMIN, [self::ADMIN, self::DOMAIN_ADMIN, self::REGULAR_USER, self::FOREIGN_USER], []];
        yield 'domain admin sees their domain' => [self::DOMAIN_ADMIN, [self::DOMAIN_ADMIN, self::REGULAR_USER, self::NEIGHBOUR], [self::FOREIGN_USER]];
        yield 'user sees their own' => [self::REGULAR_USER, [self::REGULAR_USER], [self::NEIGHBOUR, self::DOMAIN_ADMIN, self::FOREIGN_USER]];
    }

    /**
     * @param list<string> $visible
     * @param list<string> $hidden
     */
    #[DataProvider('visibleTokens')]
    public function testListShowsTheTokensTheActorMayManage(string $actor, array $visible, array $hidden): void
    {
        $this->client->loginUser($this->findUser($actor), 'main');

        $this->client->request(Request::METHOD_GET, '/api-token');

        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getResponse()->getContent();

        foreach ($visible as $email) {
            self::assertStringContainsString('token of ' . $email, $page);
        }

        foreach ($hidden as $email) {
            self::assertStringNotContainsString('token of ' . $email, $page);
        }
    }

    public function testFormOnlyPreselectsReadingScopes(): void
    {
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[type=checkbox][value="mail:send"][checked]'));
        self::assertCount(0, $crawler->filter('input[type=checkbox][value="manage:write"][checked]'));
        self::assertCount(1, $crawler->filter('input[type=checkbox][value="mail:read"][checked]'));
        self::assertCount(1, $crawler->filter('input[type=checkbox][value="manage:read"][checked]'));
        self::assertSame('90', $crawler->filter('input[name="api_token[lifetimeDays]"]')->attr('value'));
    }

    public function testUserCreatesATokenAndSeesTheSecretOnlyOnce(): void
    {
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');
        $form = $crawler->filter('form[name="api_token"]')->form();
        $form['api_token[name]'] = 'my agent';
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $secret = $this->client->getCrawler()->filter('[data-api-token-secret]')->text();
        $token = static::getContainer()->get(ApiTokenRepository::class)->findOneBy(['name' => 'my agent']);
        self::assertInstanceOf(ApiToken::class, $token);
        self::assertSame(self::REGULAR_USER, $token->getOwner()->getUserIdentifier());
        self::assertSame(hash('sha256', $secret), $token->getSecretHash());
        self::assertNotContains(ApiTokenScope::MailSend, $token->getScopes());
        self::assertEqualsWithDelta(new \DateTimeImmutable('+90 days')->getTimestamp(), $token->getExpiresAt()->getTimestamp(), 5);

        $this->client->request(Request::METHOD_GET, '/api-token');
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());

        $this->client->getCookieJar()->clear();
        $this->initialiseSession($secret);
    }

    public function testLifetimeAboveTheMaximumIsRefused(): void
    {
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');
        $form = $crawler->filter('form[name="api_token"]')->form();
        $form['api_token[name]'] = 'too long';
        $form['api_token[lifetimeDays]'] = '91';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNull(static::getContainer()->get(ApiTokenRepository::class)->findOneBy(['name' => 'too long']));
    }

    public function testTokenWithoutScopesIsRefused(): void
    {
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');
        $form = $crawler->filter('form[name="api_token"]')->form();
        $form['api_token[name]'] = 'no scopes';

        $checkboxes = $form->get('api_token[scopes]');
        self::assertIsArray($checkboxes);

        foreach ($checkboxes as $checkbox) {
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->untick();
        }

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function permittedRevocations(): iterable
    {
        yield 'user, own token' => [self::REGULAR_USER, self::REGULAR_USER];
        yield 'domain admin, token in their domain' => [self::DOMAIN_ADMIN, self::REGULAR_USER];
        yield 'domain admin, own token' => [self::DOMAIN_ADMIN, self::DOMAIN_ADMIN];
        yield 'admin, token of any domain' => [self::ADMIN, self::FOREIGN_USER];
    }

    #[DataProvider('permittedRevocations')]
    public function testPermittedRevocation(string $actor, string $owner): void
    {
        $tokenId = $this->tokenIdOf($owner);
        $this->client->loginUser($this->findUser($actor), 'main');
        $csrf = $this->issueCsrfToken($this->client, '/api-token', ApiTokenCrudController::REVOKE_CSRF_TOKEN_ID);

        $this->client->request(Request::METHOD_POST, \sprintf('/api-token/revoke/%d', $tokenId), ['token' => $csrf]);

        self::assertResponseRedirects();
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(ApiToken::class, $tokenId)?->getRevokedAt());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function forbiddenRevocations(): iterable
    {
        yield 'user, token of a neighbour' => [self::REGULAR_USER, self::NEIGHBOUR];
        yield 'user, token of an admin' => [self::REGULAR_USER, self::ADMIN];
        yield 'domain admin, token of a foreign domain' => [self::DOMAIN_ADMIN, self::FOREIGN_USER];
    }

    #[DataProvider('forbiddenRevocations')]
    public function testForbiddenRevocation(string $actor, string $owner): void
    {
        $tokenId = $this->tokenIdOf($owner);
        $this->client->loginUser($this->findUser($actor), 'main');
        $csrf = $this->issueCsrfToken($this->client, '/api-token', ApiTokenCrudController::REVOKE_CSRF_TOKEN_ID);

        $this->client->request(Request::METHOD_POST, \sprintf('/api-token/revoke/%d', $tokenId), ['token' => $csrf]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(ApiToken::class, $tokenId)?->getRevokedAt());
    }

    public function testRevokeFormOnTheListCarriesItsOwnCsrfToken(): void
    {
        $tokenId = $this->tokenIdOf(self::REGULAR_USER);
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');
        $crawler = $this->client->request(Request::METHOD_GET, '/api-token');

        $this->client->submit($crawler->filter(\sprintf('form[action*="/api-token/revoke/%d"]', $tokenId))->form());

        self::assertResponseRedirects();
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(ApiToken::class, $tokenId)?->getRevokedAt());
    }

    public function testRevocationNeedsACsrfToken(): void
    {
        $tokenId = $this->tokenIdOf(self::REGULAR_USER);
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $this->client->request(Request::METHOD_POST, \sprintf('/api-token/revoke/%d', $tokenId));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRevocationRefusesGet(): void
    {
        $tokenId = $this->tokenIdOf(self::REGULAR_USER);
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $this->client->request(Request::METHOD_GET, \sprintf('/api-token/revoke/%d', $tokenId));

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testMenuLinksToThePageWhenEnabled(): void
    {
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/api-token', (string) $this->client->getResponse()->getContent());
    }

    public function testPagesAndMenuEntryAreGoneWhenDisabled(): void
    {
        $this->setEnvironment('MCP_ENABLED', 'false');
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $this->client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/api-token', (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, '/api-token');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->client->request(Request::METHOD_GET, '/api-token/new');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function createUser(Domain $domain, string $name): User
    {
        $user = new User();
        $user->setName($name);
        $user->setDomain($domain);
        $user->setPassword('irrelevant');
        $this->entityManager()->persist($user);

        return $user;
    }

    private function storeNamedToken(string $email): void
    {
        $this->entityManager()->persist(new ApiToken(
            $this->findUser($email),
            'token of ' . $email,
            ApiToken::hashSecret('secret of ' . $email),
            [ApiTokenScope::ManageRead],
            new \DateTimeImmutable('+1 day'),
        ));
        $this->entityManager()->flush();
    }

    private function tokenIdOf(string $email): int
    {
        $token = static::getContainer()->get(ApiTokenRepository::class)->findOneBy(['name' => 'token of ' . $email]);
        self::assertInstanceOf(ApiToken::class, $token);

        return (int) $token->getId();
    }

    private function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
