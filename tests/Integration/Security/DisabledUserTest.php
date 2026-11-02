<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;

class DisabledUserTest extends WebTestCase
{
    private const string EMAIL = 'admin@example.com';
    private const string PASSWORD = 'changeme';

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
    }

    public function testEnabledUserCanLogInThroughTheForm(): void
    {
        $this->prepareUser(enabled: true);

        $this->submitLoginForm();

        $this->assertLoggedIn();
    }

    public function testDisabledUserCannotLogInThroughTheForm(): void
    {
        $this->prepareUser(enabled: false);

        $this->submitLoginForm();

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
        $this->assertLoggedOut();
    }

    public function testSessionOfEnabledUserStaysValid(): void
    {
        $this->startSession();

        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
    }

    public function testSessionEndsOnTheNextRequestAfterTheUserIsDisabled(): void
    {
        $this->startSession();

        $this->findUser()->setEnabled(false);
        $this->flush();
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/login');
    }

    public function testEndedSessionDoesNotResumeWhenTheUserIsEnabledAgain(): void
    {
        $this->startSession();

        $this->findUser()->setEnabled(false);
        $this->flush();
        $this->client->request(Request::METHOD_GET, '/');

        $this->findUser()->setEnabled(true);
        $this->flush();
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/login');
    }

    public function testSessionEndsWhenThePasswordChanges(): void
    {
        $this->startSession();

        $this->findUser()->setPassword('another-hash');
        $this->flush();
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/login');
    }

    public function testSessionEndsWhenTheRoleChanges(): void
    {
        $this->startSession();

        $this->findUser()->setAdmin(false);
        $this->flush();
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseRedirects('/login');
    }

    public function testOidcLoginReEnablesLocallyDisabledUser(): void
    {
        $this->findUser()->setEnabled(false);
        $this->flush();

        self::getContainer()->set('hwi_oauth.http_client', new MockHttpClient([
            new JsonMockResponse(['access_token' => 'access-token', 'token_type' => 'bearer']),
            new JsonMockResponse(['sub' => self::EMAIL, 'groups' => ['admin']]),
        ]));

        // The mock is set on this kernel and the CSRF state lives in the session, so keep both across requests.
        $this->client->disableReboot();
        $this->client->request(Request::METHOD_GET, '/connect/default');
        self::assertResponseRedirects();
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $authorisationRequest);

        $this->client->request(Request::METHOD_GET, '/login/check-oauth', [
            'code' => 'authorisation-code',
            'state' => $authorisationRequest['state'],
        ]);

        self::assertResponseRedirects('http://localhost/');
        $this->assertLoggedIn();
        self::assertTrue($this->findUser()->getEnabled());
    }

    private function startSession(): void
    {
        $this->client->loginUser($this->findUser(), 'main');
        $this->client->request(Request::METHOD_GET, '/');

        self::assertResponseIsSuccessful();
    }

    private function flush(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    private function prepareUser(bool $enabled): void
    {
        $user = $this->findUser();
        $user->setPlainPassword(self::PASSWORD);
        $user->setPassword(self::PASSWORD);
        $user->setEnabled($enabled);

        $this->flush();
    }

    private function findUser(): User
    {
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmailAddress(self::EMAIL);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function submitLoginForm(): void
    {
        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $this->client->submit($crawler->filter('form')->form([
            '_username' => self::EMAIL,
            '_password' => self::PASSWORD,
        ]));
    }

    private function assertLoggedIn(): void
    {
        $this->client->request(Request::METHOD_GET, '/login');

        self::assertResponseRedirects();
    }

    private function assertLoggedOut(): void
    {
        $this->client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
    }
}
