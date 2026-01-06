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

    public function testAutoconfigWithEmailContainingPlusSign(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => 'user+tag@example.com']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<username>user+tag@example.com</username>', $content);
    }

    public function testAutoconfigWithEmailContainingSpecialCharacters(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        // Test with email containing dots and hyphens (valid email characters)
        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => 'user.name-test@example.com']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('<username>user.name-test@example.com</username>', $content);
    }

    public function testAutoconfigBothServersUseSameEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => 'user@example.com']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        // Count occurrences of the email address - should appear twice (IMAP and SMTP)
        $emailCount = substr_count($content, 'user@example.com');
        self::assertSame(2, $emailCount);
    }

    public function testAutoconfigBothServersUseSameHostname(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'mail.example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        // Hostname should appear twice (IMAP and SMTP)
        $hostnameCount = substr_count($content, '<hostname>mail.example.com</hostname>');
        self::assertSame(2, $hostnameCount);
    }

    public function testAutoconfigRejectsPutMethod(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_PUT, '/mail/config-v1.1.xml');
        self::assertResponseStatusCodeSame(405);
    }

    public function testAutoconfigRejectsDeleteMethod(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_DELETE, '/mail/config-v1.1.xml');
        self::assertResponseStatusCodeSame(405);
    }

    public function testAutoconfigRejectsPatchMethod(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_PATCH, '/mail/config-v1.1.xml');
        self::assertResponseStatusCodeSame(405);
    }

    public function testAutoconfigWithVeryLongEmailAddress(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        // Create a very long but valid email address
        $longLocalPart = str_repeat('a', 64); // Max length for local part
        $email = $longLocalPart . '@example.com';

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => $email]);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // Should either contain the email or fall back to placeholder if too long
        $containsEmail = str_contains($content, $email);
        $containsPlaceholder = str_contains($content, '%EMAILADDRESS%');
        self::assertTrue($containsEmail || $containsPlaceholder);
    }

    public function testAutoconfigWithEmailContainingUnicode(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        // Test with email containing unicode characters (should be invalid per FILTER_VALIDATE_EMAIL)
        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml', ['emailaddress' => 'üser@example.com']);
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        // Should fall back to placeholder for invalid email
        self::assertStringContainsString('<username>%EMAILADDRESS%</username>', $content);
    }

    public function testAutoconfigXmlIsWellFormed(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        // Verify XML is well-formed by parsing it
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_clear_errors();

        self::assertNotFalse($xml, 'XML should be well-formed');
        self::assertSame('1.1', (string) $xml['version']);
    }

    public function testAutoconfigContainsBothImapAndSmtpServers(): void
    {
        $client = static::createClient();
        $client->setServerParameter('HTTP_HOST', 'example.com');

        $client->request(Request::METHOD_GET, '/mail/config-v1.1.xml');
        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        // Verify both server types are present
        self::assertStringContainsString('<incomingServer type="imap">', $content);
        self::assertStringContainsString('<outgoingServer type="smtp">', $content);

        // Verify IMAP comes before SMTP
        $imapPosition = strpos($content, '<incomingServer type="imap">');
        $smtpPosition = strpos($content, '<outgoingServer type="smtp">');
        self::assertNotFalse($imapPosition);
        self::assertNotFalse($smtpPosition);
        self::assertLessThan($smtpPosition, $imapPosition);
    }
}
