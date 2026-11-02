<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class OAuthFailureTest extends WebTestCase
{
    private const string LOGIN_FAILED_MESSAGE = 'OAuth login failed. Please try again.';

    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
    }

    public function testInvalidCsrfStateReturnsToTheLoginPageWithAnError(): void
    {
        $this->client->request(Request::METHOD_GET, '/login/check-oauth', [
            'code' => 'authorisation-code',
            'state' => 'forged-state',
        ]);

        $this->assertReturnedToLoginPageWithError();
    }

    public function testCallbackWithoutCodeReturnsToTheLoginPageWithAnError(): void
    {
        $this->client->request(Request::METHOD_GET, '/login/check-oauth', [
            'error' => 'access_denied',
        ]);

        $this->assertReturnedToLoginPageWithError();
    }

    public function testIdentityProviderErrorReturnsToTheLoginPageWithoutItsDetails(): void
    {
        $this->completeAuthorisation(new MockHttpClient([
            new JsonMockResponse(['error' => 'invalid_grant', 'error_description' => 'client secret rejected for tenant-42']),
        ]));

        $this->assertReturnedToLoginPageWithError();
        self::assertStringNotContainsString('tenant-42', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownAccountStaysInTheRegistrationFlowWithoutExposingItsDetails(): void
    {
        // The registration controller fetches the user information a second time.
        $this->completeAuthorisation(new MockHttpClient([
            new JsonMockResponse(['access_token' => 'access-token', 'token_type' => 'bearer']),
            new JsonMockResponse(['sub' => 'unknown@example.com', 'groups' => []]),
            new JsonMockResponse(['sub' => 'unknown@example.com', 'groups' => []]),
        ]));

        self::assertResponseRedirects();
        self::assertStringStartsWith('/connect/registration/', (string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));

        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringContainsString('No Account Found', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('unknown@example.com', (string) $this->client->getResponse()->getContent());
    }

    private function completeAuthorisation(MockHttpClient $identityProvider): void
    {
        self::getContainer()->set('hwi_oauth.http_client', $identityProvider);

        // The mock is set on this kernel and the CSRF state lives in the session, so keep both across requests.
        $this->client->disableReboot();
        $this->client->request(Request::METHOD_GET, '/connect/default');
        self::assertResponseRedirects();
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $authorisationRequest);

        $this->client->request(Request::METHOD_GET, '/login/check-oauth', [
            'code' => 'authorisation-code',
            'state' => $authorisationRequest['state'],
        ]);
    }

    private function assertReturnedToLoginPageWithError(): void
    {
        self::assertResponseRedirects('/login');

        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-danger', self::LOGIN_FAILED_MESSAGE);
    }
}
