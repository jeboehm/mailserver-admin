<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\User;

use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\Helper\UserTrait;

class MobileConfigActionTest extends WebTestCase
{
    use UserTrait;

    private KernelBrowser $client;

    private string $certPath;

    private string $keyPath;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $this->certPath = __DIR__ . '/../../../../tls/tls.crt';
        $this->keyPath = __DIR__ . '/../../../../tls/tls.key';

        $this->loginClient($this->client);
    }

    public function testMobileConfigDownloadRequiresCertificates(): void
    {
        if (!\function_exists('openssl_cms_sign')) {
            $this->markTestSkipped('OpenSSL CMS signing is not available');
        }

        if (!is_readable($this->certPath) || !is_readable($this->keyPath)) {
            $this->markTestSkipped('Test certificates not found at tls/tls.crt and tls/tls.key');
        }

        $adminUrlGenerator = $this->client->getContainer()->get(AdminUrlGenerator::class);
        \assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_mobileconfig_download')->generateUrl();

        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();
    }

    public function testMobileConfigDownloadReturnsCorrectContentType(): void
    {
        if (!\function_exists('openssl_cms_sign')) {
            $this->markTestSkipped('OpenSSL CMS signing is not available');
        }

        if (!is_readable($this->certPath) || !is_readable($this->keyPath)) {
            $this->markTestSkipped('Test certificates not found at tls/tls.crt and tls/tls.key');
        }

        $adminUrlGenerator = $this->client->getContainer()->get(AdminUrlGenerator::class);
        \assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_mobileconfig_download')->generateUrl();

        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $response = $this->client->getResponse();
        self::assertSame('application/x-apple-aspen-config', $response->headers->get('Content-Type'));
    }

    public function testMobileConfigDownloadReturnsAttachment(): void
    {
        if (!\function_exists('openssl_cms_sign')) {
            $this->markTestSkipped('OpenSSL CMS signing is not available');
        }

        if (!is_readable($this->certPath) || !is_readable($this->keyPath)) {
            $this->markTestSkipped('Test certificates not found at tls/tls.crt and tls/tls.key');
        }

        $adminUrlGenerator = $this->client->getContainer()->get(AdminUrlGenerator::class);
        \assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_mobileconfig_download')->generateUrl();

        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $response = $this->client->getResponse();
        $contentDisposition = $response->headers->get('Content-Disposition');
        self::assertIsString($contentDisposition);
        self::assertStringContainsString('attachment', $contentDisposition);
        self::assertStringContainsString('.mobileconfig', $contentDisposition);
    }

    public function testMobileConfigDownloadReturnsSignedProfile(): void
    {
        if (!\function_exists('openssl_cms_sign')) {
            $this->markTestSkipped('OpenSSL CMS signing is not available');
        }

        if (!is_readable($this->certPath) || !is_readable($this->keyPath)) {
            $this->markTestSkipped('Test certificates not found at tls/tls.crt and tls/tls.key');
        }

        $adminUrlGenerator = $this->client->getContainer()->get(AdminUrlGenerator::class);
        \assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_mobileconfig_download')->generateUrl();

        $this->client->request(Request::METHOD_GET, $url);
        self::assertResponseIsSuccessful();

        $response = $this->client->getResponse();
        $content = $response->getContent();
        self::assertIsString($content);
        self::assertNotEmpty($content);
        // Signed profile should be in DER format (binary), so it should be larger than a typical XML
        self::assertGreaterThan(100, \strlen($content));
    }
}
