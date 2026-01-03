<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Tests\Unit\Service\MobileConfig;

use App\Entity\Domain;
use App\Entity\User;
use App\Service\MobileConfig\MobileConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class MobileConfigServiceTest extends TestCase
{
    private string $tempDir;
    private MockObject&Environment $twig;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mobileconfig_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->twig = $this->createMock(Environment::class);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }
    }

    public function testGenerateSignedProfileThrowsExceptionWhenCertificatesNotConfigured(): void
    {
        $service = new MobileConfigService(
            twig: $this->twig,
            serverCertPath: null,
            serverKeyPath: null,
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
        );

        $user = $this->createUser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MobileConfig certificates are not configured');
        $this->twig->expects($this->never())->method('render');

        $service->generateSignedProfile($user);
    }

    public function testGenerateSignedProfileThrowsExceptionWhenCertFileNotReadable(): void
    {
        $certPath = $this->tempDir . '/nonexistent.crt';
        $keyPath = $this->tempDir . '/nonexistent.key';

        $service = new MobileConfigService(
            twig: $this->twig,
            serverCertPath: $certPath,
            serverKeyPath: $keyPath,
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
        );

        $user = $this->createUser();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server certificate file');
        $this->twig->expects($this->never())->method('render');

        $service->generateSignedProfile($user);
    }

    public function testGenerateUnsignedProfileContainsCorrectData(): void
    {
        // Create dummy certificate files
        $certPath = $this->tempDir . '/server.crt';
        $keyPath = $this->tempDir . '/server.key';

        file_put_contents($certPath, '-----BEGIN CERTIFICATE-----\nDUMMY\n-----END CERTIFICATE-----');
        file_put_contents($keyPath, '-----BEGIN PRIVATE KEY-----\nDUMMY\n-----END PRIVATE KEY-----');

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                'admin/mobileconfig/mobileconfig.xml.twig',
                $this->callback(function (array $vars) {
                    return isset($vars['email'], $vars['mailServerHost'], $vars['imapPort'], $vars['smtpPort'], $vars['portsAreSsl'])
                        && 'testuser@example.com' === $vars['email']
                        && 'mail.example.com' === $vars['mailServerHost']
                        && 993 === $vars['imapPort']
                        && 465 === $vars['smtpPort']
                        && true === $vars['portsAreSsl'];
                })
            )
            ->willReturn('<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0">
<dict>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>EmailAddress</key>
            <string>testuser@example.com</string>
            <key>IncomingMailServerHostName</key>
            <string>mail.example.com</string>
            <key>IncomingMailServerPortNumber</key>
            <integer>993</integer>
            <key>IncomingMailServerUseSSL</key>
            <true/>
            <key>OutgoingMailServerPortNumber</key>
            <integer>465</integer>
            <key>EmailAccountType</key>
            <string>EmailTypeIMAP</string>
        </dict>
    </array>
</dict>
</plist>');

        $service = new MobileConfigService(
            twig: $this->twig,
            serverCertPath: $certPath,
            serverKeyPath: $keyPath,
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
        );

        $user = $this->createUser('testuser', 'example.com');

        // Use reflection to test private method
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('generateUnsignedProfile');
        $method->setAccessible(true);

        $xml = $method->invoke($service, $user, 'mail.example.com');

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<plist version="1.0">', $xml);
        $this->assertStringContainsString('testuser@example.com', $xml);
        $this->assertStringContainsString('mail.example.com', $xml);
        $this->assertStringContainsString('<integer>993</integer>', $xml);
        $this->assertStringContainsString('<integer>465</integer>', $xml);
        $this->assertStringContainsString('<true/>', $xml); // portsAreSsl
        $this->assertStringContainsString('EmailTypeIMAP', $xml);
    }

    public function testGenerateUnsignedProfileWithSslFalse(): void
    {
        $certPath = $this->tempDir . '/server.crt';
        $keyPath = $this->tempDir . '/server.key';

        file_put_contents($certPath, 'DUMMY');
        file_put_contents($keyPath, 'DUMMY');

        $this->twig->expects($this->once())
            ->method('render')
            ->willReturn('<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0">
<dict>
    <key>PayloadContent</key>
    <array>
        <dict>
            <key>IncomingMailServerUseSSL</key>
            <false/>
        </dict>
    </array>
</dict>
</plist>');

        $service = new MobileConfigService(
            twig: $this->twig,
            serverCertPath: $certPath,
            serverKeyPath: $keyPath,
            imapPort: 143,
            smtpPort: 587,
            portsAreSsl: false,
        );

        $user = $this->createUser();

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('generateUnsignedProfile');
        $method->setAccessible(true);

        $xml = $method->invoke($service, $user, 'mail.example.com');

        $this->assertStringContainsString('<false/>', $xml);
    }

    private function createUser(string $name = 'testuser', string $domainName = 'example.com'): User
    {
        $domain = new Domain();
        $domain->setName($domainName);

        $user = new User();
        $user->setName($name);
        $user->setDomain($domain);

        return $user;
    }
}
