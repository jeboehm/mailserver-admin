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
    private MockObject&Environment $twigMock;

    private string $certPath;

    private string $keyPath;

    protected function setUp(): void
    {
        $this->twigMock = $this->createMock(Environment::class);
        $this->certPath = __DIR__ . '/../../../../tls/tls.crt';
        $this->keyPath = __DIR__ . '/../../../../tls/tls.key';
    }

    public function testGenerateSignedProfileThrowsExceptionWhenCertPathIsNull(): void
    {
        $user = $this->createUserWithDomain('test@example.com', 'example.com');

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: null,
            serverKeyPath: $this->keyPath,
            mailname: 'mail.example.com'
        );

        $this->twigMock->expects($this->never())->method('render');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MobileConfig certificates are not configured');

        $service->generateSignedProfile($user);
    }

    public function testGenerateSignedProfileThrowsExceptionWhenKeyPathIsNull(): void
    {
        $user = $this->createUserWithDomain('test@example.com', 'example.com');

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: null,
            mailname: 'mail.example.com'
        );

        $this->twigMock->expects($this->never())->method('render');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MobileConfig certificates are not configured');

        $service->generateSignedProfile($user);
    }

    public function testGenerateSignedProfileThrowsExceptionWhenCertFileIsNotReadable(): void
    {
        $user = $this->createUserWithDomain('test@example.com', 'example.com');
        $nonExistentCertPath = __DIR__ . '/../../../../tls/non_existent.crt';

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $nonExistentCertPath,
            serverKeyPath: $this->keyPath,
            mailname: 'mail.example.com'
        );

        $this->twigMock->expects($this->never())->method('render');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server certificate file');

        $service->generateSignedProfile($user);
    }

    public function testGenerateSignedProfileThrowsExceptionWhenKeyFileIsNotReadable(): void
    {
        $user = $this->createUserWithDomain('test@example.com', 'example.com');
        $nonExistentKeyPath = __DIR__ . '/../../../../tls/non_existent.key';

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: $nonExistentKeyPath,
            mailname: 'mail.example.com'
        );

        $this->twigMock->expects($this->never())->method('render');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Server key file');

        $service->generateSignedProfile($user);
    }

    public function testGenerateSignedProfileThrowsExceptionWhenMailnameAndDomainAreNull(): void
    {
        $user = new User();
        $user->setName('test');

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: $this->keyPath,
            mailname: null
        );

        $this->twigMock->expects($this->never())->method('render');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine mail server hostname');

        $service->generateSignedProfile($user);
    }

    public function testGenerateSignedProfileUsesMailnameWhenProvided(): void
    {
        $user = $this->createUserWithDomain('test@example.com', 'example.com');
        $mailname = 'mail.example.com';
        $expectedXml = '<?xml version="1.0"?><plist></plist>';

        $this->twigMock
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/mobileconfig/mobileconfig.xml.twig',
                $this->callback(function (array $params) use ($mailname) {
                    return 'test@example.com' === $params['email']
                        && 'test' === $params['accountName']
                        && 'example.com' === $params['companyName']
                        && $params['mailServerHost'] === $mailname
                        && 993 === $params['imapPort']
                        && 465 === $params['smtpPort']
                        && true === $params['portsAreSsl']
                        && isset($params['uuid1'])
                        && isset($params['uuid2']);
                })
            )
            ->willReturn($expectedXml);

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: $this->keyPath,
            mailname: $mailname
        );

        try {
            $service->generateSignedProfile($user);
        } catch (\UnexpectedValueException $e) {
            // Expected if OpenSSL signing fails with test certificates
            // The important part is that Twig was called correctly
            $this->assertStringContainsString('Error signing mobileprofile', $e->getMessage());
        }
    }

    public function testGenerateSignedProfileUsesDomainNameWhenMailnameIsNull(): void
    {
        $user = $this->createUserWithDomain('test@example.com', 'example.com');
        $expectedXml = '<?xml version="1.0"?><plist></plist>';

        $this->twigMock
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/mobileconfig/mobileconfig.xml.twig',
                $this->callback(function (array $params) {
                    return 'test@example.com' === $params['email']
                        && 'test' === $params['accountName']
                        && 'example.com' === $params['companyName']
                        && 'example.com' === $params['mailServerHost']
                        && 993 === $params['imapPort']
                        && 587 === $params['smtpPort']
                        && false === $params['portsAreSsl']
                        && isset($params['uuid1'])
                        && isset($params['uuid2']);
                })
            )
            ->willReturn($expectedXml);

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 587,
            portsAreSsl: false,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: $this->keyPath,
            mailname: null
        );

        try {
            $service->generateSignedProfile($user);
        } catch (\UnexpectedValueException $e) {
            // Expected if OpenSSL signing fails with test certificates
            // The important part is that Twig was called correctly
            $this->assertStringContainsString('Error signing mobileprofile', $e->getMessage());
        }
    }

    public function testGenerateSignedProfileUsesDefaultCompanyNameWhenDomainIsNull(): void
    {
        $user = new User();
        $user->setName('test');
        $expectedXml = '<?xml version="1.0"?><plist></plist>';

        $this->twigMock
            ->expects($this->once())
            ->method('render')
            ->with(
                'admin/mobileconfig/mobileconfig.xml.twig',
                $this->callback(function (array $params) {
                    // When user has no domain, __toString() returns empty string
                    return '' === $params['email']
                        && 'test' === $params['accountName']
                        && 'mailserver' === $params['companyName']
                        && 'mail.example.com' === $params['mailServerHost']
                        && isset($params['uuid1'])
                        && isset($params['uuid2']);
                })
            )
            ->willReturn($expectedXml);

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: $this->keyPath,
            mailname: 'mail.example.com'
        );

        try {
            $service->generateSignedProfile($user);
        } catch (\UnexpectedValueException $e) {
            // Expected if OpenSSL signing fails with test certificates
            // The important part is that Twig was called correctly
            $this->assertStringContainsString('Error signing mobileprofile', $e->getMessage());
        }
    }

    public function testGenerateSignedProfileGeneratesDifferentUuids(): void
    {
        $user = $this->createUserWithDomain('test@example.com', 'example.com');
        $expectedXml = '<?xml version="1.0"?><plist></plist>';
        $capturedUuids = [];

        $this->twigMock
            ->expects($this->once())
            ->method('render')
            ->willReturnCallback(function (string $template, array $params) use (&$capturedUuids, $expectedXml) {
                $capturedUuids[] = $params['uuid1'];
                $capturedUuids[] = $params['uuid2'];

                return $expectedXml;
            });

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: $this->keyPath,
            mailname: 'mail.example.com'
        );

        try {
            $service->generateSignedProfile($user);
        } catch (\UnexpectedValueException $e) {
            // Expected if OpenSSL signing fails
        }

        $this->assertCount(2, $capturedUuids);
        $this->assertNotEquals($capturedUuids[0], $capturedUuids[1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $capturedUuids[0]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $capturedUuids[1]);
    }

    public function testGenerateSignedProfileWithValidCertificates(): void
    {
        if (!function_exists('openssl_cms_sign')) {
            $this->markTestSkipped('OpenSSL CMS signing is not available');
        }

        if (!is_readable($this->certPath) || !is_readable($this->keyPath)) {
            $this->markTestSkipped('Test certificates not found at tls/tls.crt and tls/tls.key');
        }

        $user = $this->createUserWithDomain('test@example.com', 'example.com');
        $expectedXml = '<?xml version="1.0"?><plist></plist>';

        $this->twigMock
            ->expects($this->once())
            ->method('render')
            ->willReturn($expectedXml);

        $service = new MobileConfigService(
            imapPort: 993,
            smtpPort: 465,
            portsAreSsl: true,
            twig: $this->twigMock,
            serverCertPath: $this->certPath,
            serverKeyPath: $this->keyPath,
            mailname: 'mail.example.com'
        );

        $result = $service->generateSignedProfile($user);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
        // Signed profile should be in DER format (binary)
        $this->assertGreaterThan(strlen($expectedXml), strlen($result));
    }

    private function createUserWithDomain(string $email, string $domainName): User
    {
        $domain = new Domain();
        $domain->setName($domainName);

        $user = new User();
        $user->setName(explode('@', $email)[0]);
        $user->setDomain($domain);

        return $user;
    }
}
