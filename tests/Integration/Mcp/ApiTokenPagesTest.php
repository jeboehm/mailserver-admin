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
use App\Entity\Alias;
use App\Entity\ApiToken;
use App\Entity\Domain;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ApiToken\ApiTokenScope;
use App\Service\ApiToken\SenderMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
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
        // Owned by plainuser, and the only kinds of alias that are not: another user's, an external one.
        $this->createAlias($ownDomain, 'plain-alias', self::REGULAR_USER);
        $this->createAlias($ownDomain, 'shared', self::NEIGHBOUR . ',external@elsewhere.invalid');
        $this->createAlias($ownDomain, 'team', self::NEIGHBOUR);
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
     * @return iterable<string, array{string, list<string>}>
     */
    public static function ownedAddresses(): iterable
    {
        yield 'user' => [self::REGULAR_USER, [self::REGULAR_USER, 'plain-alias@example.com']];
        yield 'domain admin' => [self::DOMAIN_ADMIN, [self::DOMAIN_ADMIN]];
        yield 'admin' => [self::ADMIN, [self::ADMIN]];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('ownedAddresses')]
    public function testFixedSenderOffersExactlyTheOwnedAddresses(string $actor, array $expected): void
    {
        $this->client->loginUser($this->findUser($actor), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');

        self::assertResponseIsSuccessful();
        self::assertSame(
            $expected,
            $crawler->filter('select[name="api_token[senderAddress]"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')),
        );
    }

    /**
     * The browser shows the sender fields only while mail:send or mail:draft is ticked, and the address
     * only for a fixed sender. Without a browser, this checks that the page asks it to.
     */
    public function testSenderChoiceIsTiedToTheSendingScopes(): void
    {
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');

        $form = $crawler->filter('form[name="api_token"][data-controller~="api-token-sender"]');
        self::assertCount(1, $form);
        self::assertSame(
            ['mail:send', 'mail:draft'],
            $form->filter('[data-api-token-sender-target="scope"][data-sends-mail="true"]')->each(static fn (Crawler $box): string => (string) $box->attr('value')),
        );
        self::assertCount(1, $form->filter('[data-api-token-sender-target="sender"] input[name="api_token[senderMode]"][value="primary"][checked]'));
        self::assertCount(1, $form->filter('[data-api-token-sender-target="address"] select[name="api_token[senderAddress]"]'));
    }

    /**
     * The page starts in the state the browser would toggle it to, so the sender choice stays out of
     * the way without JavaScript too.
     */
    public function testSenderChoiceStartsHiddenWithoutASendingScope(): void
    {
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');

        self::assertCount(1, $crawler->filter('[data-api-token-sender-target="sender"][hidden]'));
        self::assertCount(1, $crawler->filter('[data-api-token-sender-target="address"][hidden]'));
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function redisplayedSenders(): iterable
    {
        yield 'fixed shows the address' => [['senderMode' => 'fixed', 'senderAddress' => self::NEIGHBOUR], true];
        yield 'any hides the address' => [['senderMode' => 'any'], false];
    }

    /**
     * @param array<string, string> $sender
     */
    #[DataProvider('redisplayedSenders')]
    public function testRefusedFormKeepsTheSenderChoiceVisible(array $sender, bool $addressVisible): void
    {
        $crawler = $this->submitNewToken(self::REGULAR_USER, '', ['mail:send'], $sender);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertCount(1, $crawler->filter('[data-api-token-sender-target="sender"]:not([hidden])'));
        self::assertCount($addressVisible ? 1 : 0, $crawler->filter('[data-api-token-sender-target="address"]:not([hidden])'));
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>, SenderMode, ?string}>
     */
    public static function senderChoices(): iterable
    {
        yield 'primary is the default' => [['mail:send'], [], SenderMode::Primary, null];
        yield 'fixed alias for sending' => [['mail:send'], ['senderMode' => 'fixed', 'senderAddress' => 'plain-alias@example.com'], SenderMode::Fixed, 'plain-alias@example.com'];
        yield 'fixed primary for drafting' => [['mail:draft'], ['senderMode' => 'fixed', 'senderAddress' => self::REGULAR_USER], SenderMode::Fixed, self::REGULAR_USER];
        yield 'any drops the address' => [['mail:send', 'mail:draft'], ['senderMode' => 'any', 'senderAddress' => 'plain-alias@example.com'], SenderMode::Any, null];
        yield 'primary drops the address' => [['mail:send'], ['senderMode' => 'primary', 'senderAddress' => 'plain-alias@example.com'], SenderMode::Primary, null];
        yield 'ignored without sending or drafting' => [['manage:read'], ['senderMode' => 'fixed', 'senderAddress' => 'plain-alias@example.com'], SenderMode::Primary, null];
    }

    /**
     * @param list<string>          $scopes
     * @param array<string, string> $sender
     */
    #[DataProvider('senderChoices')]
    public function testTokenGetsTheChosenSender(array $scopes, array $sender, SenderMode $mode, ?string $address): void
    {
        $this->submitNewToken(self::REGULAR_USER, 'with sender', $scopes, $sender);

        self::assertResponseIsSuccessful();
        $token = static::getContainer()->get(ApiTokenRepository::class)->findOneBy(['name' => 'with sender']);
        self::assertInstanceOf(ApiToken::class, $token);
        self::assertSame($mode, $token->getSenderMode());
        self::assertSame($address, $token->getSenderAddress());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function refusedSenders(): iterable
    {
        yield 'alias of a neighbour' => [self::REGULAR_USER, ['senderMode' => 'fixed', 'senderAddress' => 'team@example.com']];
        yield 'alias shared with an external address' => [self::REGULAR_USER, ['senderMode' => 'fixed', 'senderAddress' => 'shared@example.com']];
        yield 'another user' => [self::REGULAR_USER, ['senderMode' => 'fixed', 'senderAddress' => self::NEIGHBOUR]];
        yield 'domain admin, a user of their domain' => [self::DOMAIN_ADMIN, ['senderMode' => 'fixed', 'senderAddress' => self::REGULAR_USER]];
        yield 'admin, a user of another domain' => [self::ADMIN, ['senderMode' => 'fixed', 'senderAddress' => self::FOREIGN_USER]];
        yield 'fixed without an address' => [self::REGULAR_USER, ['senderMode' => 'fixed', 'senderAddress' => '']];
        yield 'unknown mode' => [self::REGULAR_USER, ['senderMode' => 'everyone']];
    }

    /**
     * @param array<string, string> $sender
     */
    #[DataProvider('refusedSenders')]
    public function testSenderTheActorDoesNotOwnIsRefused(string $actor, array $sender): void
    {
        $crawler = $this->submitNewToken($actor, 'crafted', ['mail:send'], $sender);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertNotCount(0, $crawler->filter('.is-invalid[name^="api_token[sender"]'), 'The sender field is marked.');
        self::assertNull(static::getContainer()->get(ApiTokenRepository::class)->findOneBy(['name' => 'crafted']));
    }

    public function testListShowsTheSenderAndWarnsAboutAnAddressNoLongerOwned(): void
    {
        $this->storeSenderToken('send primary', [ApiTokenScope::MailSend], SenderMode::Primary, null);
        $this->storeSenderToken('draft as alias', [ApiTokenScope::MailDraft], SenderMode::Fixed, 'plain-alias@example.com');
        $this->storeSenderToken('send as gone', [ApiTokenScope::MailSend], SenderMode::Fixed, 'gone@example.com');
        $this->storeSenderToken('send as any', [ApiTokenScope::MailSend, ApiTokenScope::MailDraft], SenderMode::Any, null);
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token');

        self::assertResponseIsSuccessful();
        self::assertSame('Sender', trim($crawler->filter('th[data-column="sender"]')->text()));
        self::assertSame('', $this->senderCell($crawler, 'token of ' . self::REGULAR_USER)->text());
        self::assertSame('Primary address only', $this->senderCell($crawler, 'send primary')->text());
        self::assertSame('Any of my addresses', $this->senderCell($crawler, 'send as any')->text());

        $alias = $this->senderCell($crawler, 'draft as alias');
        self::assertStringContainsString('plain-alias@example.com', $alias->text());
        self::assertCount(0, $alias->filter('[data-api-token-sender-unowned]'));

        $gone = $this->senderCell($crawler, 'send as gone');
        self::assertStringContainsString('gone@example.com', $gone->text());
        self::assertCount(1, $gone->filter('[data-api-token-sender-unowned]'));
    }

    public function testAdminSeesTheWarningAgainstTheOwnersAddresses(): void
    {
        $this->storeSenderToken('send as plain alias', [ApiTokenScope::MailSend], SenderMode::Fixed, 'plain-alias@example.com');
        $this->client->loginUser($this->findUser(self::ADMIN), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token');

        $cell = $this->senderCell($crawler, 'send as plain alias');
        self::assertStringContainsString('plain-alias@example.com', $cell->text());
        self::assertCount(0, $cell->filter('[data-api-token-sender-unowned]'));
    }

    public function testSenderCannotBeChangedAfterCreation(): void
    {
        $tokenId = $this->tokenIdOf(self::REGULAR_USER);
        $this->client->loginUser($this->findUser(self::REGULAR_USER), 'main');

        $this->client->request(Request::METHOD_GET, \sprintf('/api-token/%d/edit', $tokenId));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
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

    /**
     * Posts the form as a crafted request could, without the browser's checks of the choices.
     *
     * @param list<string>          $scopes
     * @param array<string, string> $sender
     */
    private function submitNewToken(string $actor, string $name, array $scopes, array $sender): Crawler
    {
        $this->client->loginUser($this->findUser($actor), 'main');
        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');
        $values = $crawler->filter('form[name="api_token"]')->form()->getPhpValues();
        self::assertIsArray($values['api_token']);

        $values['api_token']['name'] = $name;
        $values['api_token']['scopes'] = $scopes;

        foreach ($sender as $field => $value) {
            $values['api_token'][$field] = $value;
        }

        return $this->client->request(Request::METHOD_POST, '/api-token/new', $values);
    }

    private function createAlias(Domain $domain, string $name, string $destination): void
    {
        $alias = new Alias();
        $alias->setName($name);
        $alias->setDomain($domain);
        $alias->setDestination($destination);
        $this->entityManager()->persist($alias);
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

    /**
     * Stores a token of the regular user.
     *
     * @param list<ApiTokenScope> $scopes
     */
    private function storeSenderToken(string $name, array $scopes, SenderMode $mode, ?string $address): void
    {
        $this->entityManager()->persist(new ApiToken(
            $this->findUser(self::REGULAR_USER),
            $name,
            ApiToken::hashSecret('secret of ' . $name),
            $scopes,
            new \DateTimeImmutable('+1 day'),
            $mode,
            $address,
        ));
        $this->entityManager()->flush();
    }

    private function senderCell(Crawler $list, string $tokenName): Crawler
    {
        $row = $list->filter('tbody tr')->reduce(static fn (Crawler $row): bool => str_contains($row->filter('td[data-column="name"]')->text(), $tokenName));
        self::assertCount(1, $row, $tokenName);

        return $row->filter('td[data-column="sender"]');
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
