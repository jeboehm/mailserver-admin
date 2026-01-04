<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Integration\Controller\Autoconfig;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

class AutodiscoverActionTest extends WebTestCase
{
    public function testAutodiscoverEndpointReturnsXml(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml');
        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        self::assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<Autodiscover xmlns="https://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">', $content);
        self::assertStringContainsString('<Response xmlns="https://schemas.microsoft.com/exchange/autodiscover/outlook/responseschema/2006a">', $content);
        self::assertStringContainsString('<Account>', $content);
        self::assertStringContainsString('<Protocol>', $content);
    }

    public function testAutodiscoverUsesRequestHostWhenMailnameNotSet(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<DisplayName>example.com</DisplayName>', $content);
        self::assertStringContainsString('<Server>example.com</Server>', $content);
    }

    public function testAutodiscoverXmlStructure(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'test.example.com');

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        // Verify IMAP protocol configuration
        self::assertStringContainsString('<Type>IMAP</Type>', $content);
        self::assertStringContainsString('<Port>143</Port>', $content);
        self::assertStringContainsString('<Encryption>TLS</Encryption>', $content);
        self::assertStringContainsString('<AuthRequired>on</AuthRequired>', $content);

        // Verify SMTP protocol configuration
        self::assertStringContainsString('<Type>SMTP</Type>', $content);
        self::assertStringContainsString('<Port>587</Port>', $content);
        self::assertStringContainsString('<LoginName>%EMAILADDRESS%</LoginName>', $content);
    }

    public function testAutodiscoverAcceptsGetMethod(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml');
        self::assertResponseIsSuccessful();
    }

    public function testAutodiscoverAcceptsPostMethod(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_POST, '/autodiscover/autodiscover.xml');
        self::assertResponseIsSuccessful();
    }

    public function testAutodiscoverWithValidEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml', ['emailaddress' => 'user@example.com']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<LoginName>user@example.com</LoginName>', $content);
    }

    public function testAutodiscoverWithInvalidEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml', ['emailaddress' => 'invalid-email']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // Invalid email should fall back to placeholder
        self::assertStringContainsString('<LoginName>%EMAILADDRESS%</LoginName>', $content);
    }

    public function testAutodiscoverWithEmptyEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml', ['emailaddress' => '']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // Empty email should fall back to placeholder
        self::assertStringContainsString('<LoginName>%EMAILADDRESS%</LoginName>', $content);
    }

    public function testAutodiscoverWithoutEmailAddressParameter(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // No email parameter should use placeholder
        self::assertStringContainsString('<LoginName>%EMAILADDRESS%</LoginName>', $content);
    }

    public function testAutodiscoverMixedCaseRoute(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/Autodiscover/Autodiscover.xml');
        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        self::assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<Autodiscover xmlns="https://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">', $content);
    }

    public function testAutodiscoverMixedCaseRouteAcceptsPostMethod(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_POST, '/Autodiscover/Autodiscover.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<Autodiscover xmlns="https://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">', $content);
    }

    public function testAutodiscoverMixedCaseRouteWithValidEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/Autodiscover/Autodiscover.xml', ['emailaddress' => 'user@example.com']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<LoginName>user@example.com</LoginName>', $content);
    }

    public function testAutodiscoverPostMethodWithValidEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        // POST requests can still have query parameters
        $client->request(Request::METHOD_POST, '/autodiscover/autodiscover.xml?emailaddress=user@example.com');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<LoginName>user@example.com</LoginName>', $content);
    }

    public function testAutodiscoverContainsBothImapAndSmtpProtocols(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'mail.example.com');

        $client->request(Request::METHOD_GET, '/autodiscover/autodiscover.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        // Count occurrences of Protocol tags - should be 2 (IMAP and SMTP)
        $protocolCount = substr_count($content, '<Protocol>');
        self::assertSame(2, $protocolCount);

        // Verify both protocols are present
        $imapPosition = strpos($content, '<Type>IMAP</Type>');
        $smtpPosition = strpos($content, '<Type>SMTP</Type>');
        self::assertNotFalse($imapPosition);
        self::assertNotFalse($smtpPosition);
        self::assertLessThan($smtpPosition, $imapPosition); // IMAP should come before SMTP
    }
}
