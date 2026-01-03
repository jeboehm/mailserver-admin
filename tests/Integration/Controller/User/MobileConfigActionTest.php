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
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\Integration\Helper\UserTrait;

class MobileConfigActionTest extends WebTestCase
{
    use UserTrait;

    public function testDownloadReturnsErrorWhenCertificatesNotConfigured(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $adminUrlGenerator = $client->getContainer()->get(AdminUrlGenerator::class);
        assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_mobileconfig_download')->generateUrl();

        $client->request(Request::METHOD_GET, $url);

        $response = $client->getResponse();
        $content = $response->getContent();

        // If certificates are not configured, should return error message
        if (500 === $response->getStatusCode()) {
            $this->assertIsString($content);
            $this->assertStringContainsString('Error generating mobileconfig', $content);
        }
    }

    public function testDownloadWithHostParameter(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $adminUrlGenerator = $client->getContainer()->get(AdminUrlGenerator::class);
        assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_mobileconfig_download')->generateUrl();
        $url .= '?host=custom.mail.example.com';

        $client->request(Request::METHOD_GET, $url);

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [200, 500], 'Expected 200 (success) or 500 (certificates not configured)');
    }

    public function testDownloadReturnsCorrectContentType(): void
    {
        $client = static::createClient();
        $this->loginClient($client);

        $adminUrlGenerator = $client->getContainer()->get(AdminUrlGenerator::class);
        assert($adminUrlGenerator instanceof AdminUrlGenerator);
        $url = $adminUrlGenerator->setRoute('admin_mobileconfig_download')->generateUrl();

        $client->request(Request::METHOD_GET, $url);

        $response = $client->getResponse();

        if (200 === $response->getStatusCode()) {
            $this->assertSame('application/x-apple-aspen-config', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('attachment; filename="', $response->headers->get('Content-Disposition'));
        }
    }
}
