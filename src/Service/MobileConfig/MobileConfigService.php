<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\MobileConfig;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Service for generating and signing iOS mobileconfig profiles.
 */
readonly class MobileConfigService
{
    public function __construct(
        #[Autowire('%env(int:MOBILECONFIG_IMAP_PORT)%')]
        private int $imapPort,
        #[Autowire('%env(int:MOBILECONFIG_SMTP_PORT)%')]
        private int $smtpPort,
        #[Autowire('%env(bool:MOBILECONFIG_PORTS_ARE_SSL)%')]
        private bool $portsAreSsl,
        private Environment $twig,
        #[Autowire('%env(default::string:MOBILECONFIG_SSL_CERT_FILE)%')]
        private ?string $serverCertPath = null,
        #[Autowire('%env(default::string:MOBILECONFIG_SSL_KEY_FILE)%')]
        private ?string $serverKeyPath = null,
    ) {
    }

    /**
     * Generate and sign a mobileconfig profile for the given user.
     *
     * @param User   $user           The user to generate the profile for
     * @param string $mailServerHost The mail server hostname (defaults to mailname or user's domain)
     *
     * @throws \RuntimeException if certificates are not configured or signing fails
     *
     * @return string The signed mobileconfig content (DER format)
     */
    public function generateSignedProfile(User $user, ?string $mailServerHost = null): string
    {
        if (null === $this->serverCertPath || null === $this->serverKeyPath) {
            throw new \RuntimeException('MobileConfig certificates are not configured. Please set MOBILECONFIG_SSL_CERT_FILE and MOBILECONFIG_SSL_KEY_FILE environment variables.');
        }

        if (!is_readable($this->serverCertPath)) {
            throw new \RuntimeException(sprintf('Server certificate file "%s" is not readable.', $this->serverCertPath));
        }

        if (!is_readable($this->serverKeyPath)) {
            throw new \RuntimeException(sprintf('Server key file "%s" is not readable.', $this->serverKeyPath));
        }

        $mailServerHost ??= $user->getDomain()?->getName() ?? throw new \RuntimeException('Cannot determine mail server hostname.');
        $unsignedProfile = $this->generateUnsignedProfile($user, $mailServerHost);

        return $this->signProfile($unsignedProfile);
    }

    /**
     * Generate the unsigned mobileconfig XML content.
     */
    private function generateUnsignedProfile(User $user, string $mailServerHost): string
    {
        return $this->twig->render('admin/mobileconfig/mobileconfig.xml.twig', [
            'email' => (string) $user,
            'companyName' => $user->getDomain()?->getName() ?? 'mailserver',
            'mailServerHost' => $mailServerHost,
            'uuid1' => $this->generateUuid(),
            'uuid2' => $this->generateUuid(),
            'imapPort' => $this->imapPort,
            'smtpPort' => $this->smtpPort,
            'portsAreSsl' => $this->portsAreSsl,
        ]);
    }

    /**
     * Sign the mobileconfig profile using OpenSSL.
     *
     * @param string $unsignedProfile The unsigned XML profile
     *
     * @return string The signed profile in DER format
     */
    private function signProfile(string $unsignedProfile): string
    {
        // Try native PHP signing first
        $signed = $this->signWithNativePhp($unsignedProfile);

        if (null !== $signed) {
            return $signed;
        }

        // Fallback to openssl command via Process
        return $this->signWithProcess($unsignedProfile);
    }

    /**
     * Attempt to sign using native PHP openssl_pkcs7_sign.
     *
     * @return string|null The signed profile in DER format, or null if signing failed
     */
    private function signWithNativePhp(string $unsignedProfile): ?string
    {
        if (!function_exists('openssl_pkcs7_sign')) {
            return null;
        }

        $tempUnsigned = tmpfile();
        if (false === $tempUnsigned) {
            return null;
        }

        $tempSigned = tmpfile();
        if (false === $tempSigned) {
            fclose($tempUnsigned);

            return null;
        }

        $tempUnsignedPath = stream_get_meta_data($tempUnsigned)['uri'];
        $tempSignedPath = stream_get_meta_data($tempSigned)['uri'];

        try {
            file_put_contents($tempUnsignedPath, $unsignedProfile);

            $result = openssl_pkcs7_sign(
                $tempUnsignedPath,
                $tempSignedPath,
                'file://' . $this->serverCertPath,
                ['file://' . $this->serverKeyPath, ''],
                [],
                PKCS7_BINARY | PKCS7_DETACHED,
            );

            if (!$result) {
                return null;
            }

            $signedContent = file_get_contents($tempSignedPath);
            if (false === $signedContent) {
                return null;
            }

            // openssl_pkcs7_sign produces PEM format, we need to convert to DER
            // Extract the PKCS7 structure from PEM
            $pemData = $signedContent;
            if (preg_match('/-----BEGIN PKCS7-----\s*(.*?)\s*-----END PKCS7-----/s', $pemData, $matches)) {
                $derData = base64_decode($matches[1], true);
                if (false !== $derData) {
                    return $derData;
                }
            }

            // If extraction fails, try to use openssl command to convert
            return null;
        } finally {
            fclose($tempUnsigned);
            fclose($tempSigned);
            @unlink($tempUnsignedPath);
            @unlink($tempSignedPath);
        }
    }

    /**
     * Sign using openssl smime command via Symfony Process.
     *
     * @throws \RuntimeException if signing fails
     *
     * @return string The signed profile in DER format
     */
    private function signWithProcess(string $unsignedProfile): string
    {
        $tempUnsigned = tmpfile();
        if (false === $tempUnsigned) {
            throw new \RuntimeException('Failed to create temporary file for unsigned profile.');
        }

        $tempSigned = tmpfile();
        if (false === $tempSigned) {
            fclose($tempUnsigned);
            throw new \RuntimeException('Failed to create temporary file for signed profile.');
        }

        $tempUnsignedPath = stream_get_meta_data($tempUnsigned)['uri'];
        $tempSignedPath = stream_get_meta_data($tempSigned)['uri'];

        try {
            file_put_contents($tempUnsignedPath, $unsignedProfile);

            $process = new Process([
                'openssl',
                'smime',
                '-sign',
                '-in',
                $tempUnsignedPath,
                '-out',
                $tempSignedPath,
                '-signer',
                $this->serverCertPath,
                '-inkey',
                $this->serverKeyPath,
                '-outform',
                'der',
                '-nodetach',
            ]);

            $process->run();

            if (!$process->isSuccessful()) {
                $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
                throw new \RuntimeException(sprintf('Failed to sign mobileconfig: %s', $errorOutput));
            }

            $signedContent = file_get_contents($tempSignedPath);
            if (false === $signedContent) {
                throw new \RuntimeException('Failed to read signed profile.');
            }

            return $signedContent;
        } finally {
            fclose($tempUnsigned);
            fclose($tempSigned);
            @unlink($tempUnsignedPath);
            @unlink($tempSignedPath);
        }
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
