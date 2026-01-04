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

class AutoconfigActionTest extends WebTestCase
{
    public function testAutoconfigEndpointReturnsXml(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml');
        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        self::assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<clientConfig version="1.1">', $content);
        self::assertStringContainsString('<emailProvider', $content);
        self::assertStringContainsString('<incomingServer type="imap">', $content);
        self::assertStringContainsString('<outgoingServer type="smtp">', $content);
    }

    public function testAutoconfigUsesRequestHostWhenMailnameNotSet(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<domain>example.com</domain>', $content);
        self::assertStringContainsString('<hostname>example.com</hostname>', $content);
    }

    public function testAutoconfigXmlStructure(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'test.example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        // Verify IMAP server configuration
        self::assertStringContainsString('<incomingServer type="imap">', $content);
        self::assertStringContainsString('<port>143</port>', $content);
        self::assertStringContainsString('<socketType>STARTTLS</socketType>', $content);
        self::assertStringContainsString('<authentication>password-cleartext</authentication>', $content);
        self::assertStringContainsString('<username>%EMAILADDRESS%</username>', $content);

        // Verify SMTP server configuration
        self::assertStringContainsString('<outgoingServer type="smtp">', $content);
        self::assertStringContainsString('<port>587</port>', $content);
        self::assertStringContainsString('<displayName>docker-mailserver</displayName>', $content);
        self::assertStringContainsString('<displayShortName>docker-mailserver</displayShortName>', $content);
    }

    public function testAutoconfigOnlyAcceptsGetMethod(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_POST, '/mail/config-v1.1.xml');
        self::assertResponseStatusCodeSame(405);
    }

    public function testAutoconfigWithValidEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => 'user@example.com']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<username>user@example.com</username>', $content);
    }

    public function testAutoconfigWithInvalidEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => 'invalid-email']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // Invalid email should fall back to placeholder
        self::assertStringContainsString('<username>%EMAILADDRESS%</username>', $content);
    }

    public function testAutoconfigWithEmptyEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => '']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // Empty email should fall back to placeholder
        self::assertStringContainsString('<username>%EMAILADDRESS%</username>', $content);
    }

    public function testAutoconfigWithoutEmailAddressParameter(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // No email parameter should use placeholder
        self::assertStringContainsString('<username>%EMAILADDRESS%</username>', $content);
    }
}
