<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Mcp;

use App\Entity\Domain;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Integration\Helper\McpClientTrait;

class McpSetupPageTest extends WebTestCase
{
    use McpClientTrait;

    private const string PAGE = '/mcp-setup';

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['MCP_ENABLED', 'MCP_ALLOWED_HOSTS', 'MCP_MAIL_READ_ENABLED', 'MCP_MAIL_SEND_ENABLED'] as $name) {
            $this->unsetEnvironment($name);
        }

        parent::tearDown();
    }

    public function testAnyLoggedInUserGetsThePageAndTheMenuEntry(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();
        $this->client->loginUser($this->createRegularUser(), 'main');

        $this->client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::PAGE, (string) $this->client->getResponse()->getContent());

        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Claude Code', 'Cursor', 'VS Code', 'Codex CLI', 'Claude Desktop'],
            $crawler->filter('[data-mcp-client]')->each(static fn (Crawler $client): string => (string) $client->attr('data-mcp-client')),
        );
        self::assertStringContainsString('/api-token/new', (string) $crawler->filter('[data-mcp-create-token]')->attr('href'));
    }

    public function testPageNeedsALogin(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();

        $this->client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testPageAndMenuEntryAreGoneWhenDisabled(): void
    {
        $this->setEnvironment('MCP_ENABLED', 'false');
        $this->client = static::createClient();
        $this->client->loginUser($this->findUser(), 'main');

        $this->client->request(Request::METHOD_GET, '/');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::PAGE, (string) $this->client->getResponse()->getContent());

        $this->client->request(Request::METHOD_GET, self::PAGE);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAddressFollowsTheRequest(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();
        $this->client->loginUser($this->findUser(), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE, server: ['HTTP_HOST' => 'mail.example.com', 'HTTPS' => 'on']);

        self::assertSame('https://mail.example.com/mcp', $crawler->filter('[data-mcp-url]')->text());
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function hosts(): iterable
    {
        yield 'listed' => ['mail.example.com', 'mail.example.com', null];
        yield 'listed, with a port' => ['mail.example.com', 'mail.example.com:8443', null];
        yield 'listed, in another case' => ['mail.example.com', 'MAIL.example.com', null];
        yield 'listed in another case in the setting' => ['MAIL.example.com', 'mail.example.com', null];
        yield 'any host allowed' => ['*', 'mail.example.com', null];
        yield 'IPv6, with a port' => ['[::1]', '[::1]:8443', null];
        yield 'not listed' => ['localhost', 'mail.example.com', 'mail.example.com'];
        yield 'not listed, with a port' => ['localhost', 'mail.example.com:8443', 'mail.example.com'];
    }

    #[DataProvider('hosts')]
    public function testWarnsAboutAHostTheEndpointRefuses(string $allowedHosts, string $hostHeader, ?string $refused): void
    {
        $this->enableMcp();
        $this->setEnvironment('MCP_ALLOWED_HOSTS', $allowedHosts);
        $this->client = static::createClient();
        $this->client->loginUser($this->findUser(), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE, server: ['HTTP_HOST' => $hostHeader]);

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('[data-mcp-refused-host]');

        if (null === $refused) {
            self::assertCount(0, $warning);

            return;
        }

        self::assertStringContainsString($refused, $warning->text());
    }

    /**
     * @return iterable<string, array{bool, bool, list<string>, string|null}>
     */
    public static function features(): iterable
    {
        yield 'no mail tools' => [false, false, ['MCP_MAIL_READ_ENABLED', 'MCP_MAIL_SEND_ENABLED'], null];
        yield 'reading mail' => [true, false, ['MCP_MAIL_SEND_ENABLED'], 'manage:write'];
        yield 'reading and sending mail' => [true, true, [], 'mail:send'];
    }

    /**
     * @param list<string> $settingsToSwitchOn
     */
    #[DataProvider('features')]
    public function testListsAllScopesAndHowToSwitchOnTheMailOnes(bool $mailRead, bool $mailSend, array $settingsToSwitchOn, ?string $warnsAbout): void
    {
        $this->enableMcp();
        $this->setEnvironment('MCP_MAIL_READ_ENABLED', $mailRead ? 'true' : 'false');
        $this->setEnvironment('MCP_MAIL_SEND_ENABLED', $mailSend ? 'true' : 'false');
        $this->client = static::createClient();
        $this->client->loginUser($this->findUser(), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['manage:read', 'manage:write', 'mail:read', 'mail:send'],
            $crawler->filter('[data-mcp-scope]')->each(static fn (Crawler $scope): string => $scope->text()),
        );
        self::assertSame(
            $settingsToSwitchOn,
            $crawler->filter('[data-mcp-scope-off] code:first-child')->each(static fn (Crawler $setting): string => $setting->text()),
        );

        $warning = $crawler->filter('[data-mcp-scope-warning]');

        if (null === $warnsAbout) {
            self::assertCount(0, $warning);

            return;
        }

        self::assertStringContainsString($warnsAbout, $warning->text());
    }

    public function testCreatedTokenLinksToTheSetupPageWithoutLeavingTheSecret(): void
    {
        $this->enableMcp();
        $this->client = static::createClient();
        $this->client->loginUser($this->findUser(), 'main');

        $crawler = $this->client->request(Request::METHOD_GET, '/api-token/new');
        $form = $crawler->filter('form[name="api_token"]')->form();
        $form['api_token[name]'] = 'setup link';
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        $link = $crawler->filter('[data-mcp-setup-link]');
        self::assertSame(self::PAGE, $link->attr('href'));
        self::assertSame('_blank', $link->attr('target'));
    }

    private function createRegularUser(): User
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $domain = $entityManager->getRepository(Domain::class)->findOneBy(['name' => 'example.com']);
        self::assertInstanceOf(Domain::class, $domain);

        $user = new User();
        $user->setName('setupreader');
        $user->setDomain($domain);
        $user->setPassword('irrelevant');
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
