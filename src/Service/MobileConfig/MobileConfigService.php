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
use Composer\CaBundle\CaBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Service for generating and signing iOS mobileconfig profiles.
 */
readonly class MobileConfigService
{
    public function __construct(
        private Environment $twig,
        #[Autowire('%env(default::string:MOBILECONFIG_SSL_CERT_FILE)%')]
        private ?string $serverCertPath = null,
        #[Autowire('%env(default::string:MOBILECONFIG_SSL_KEY_FILE)%')]
        private ?string $serverKeyPath = null,
        #[Autowire('%env(default::string:MAILNAME)%')]
        private ?string $mailname = null,
    ) {
    }

    /**
     * Generate and sign a mobileconfig profile for the given user.
     *
     * @param User $user The user to generate the profile for
     *
     * @throws \RuntimeException if certificates are not configured or signing fails
     *
     * @return string The signed mobileconfig content (DER format)
     */
    public function generateSignedProfile(User $user): string
    {
        if (null === $this->serverCertPath || null === $this->serverKeyPath) {
            throw new \RuntimeException('MobileConfig certificates are not configured. Please set MOBILECONFIG_SSL_CERT_FILE and MOBILECONFIG_SSL_KEY_FILE environment variables.');
        }

        if (!is_readable($this->serverCertPath)) {
            throw new \RuntimeException(\sprintf('Server certificate file "%s" is not readable.', $this->serverCertPath));
        }

        if (!is_readable($this->serverKeyPath)) {
            throw new \RuntimeException(\sprintf('Server key file "%s" is not readable.', $this->serverKeyPath));
        }

        $mailServerHost = $this->mailname ?? $user->getDomain()?->getName() ?? throw new \RuntimeException('Cannot determine mail server hostname.');
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
            'mailServerHost' => $mailServerHost,
            'uuid1' => $this->generateUuid(),
            'uuid2' => $this->generateUuid(),
        ]);
    }

    /**
     * Generate a UUID v4.
     */
    private function generateUuid(): string
    {
        return Uuid::v4()->toRfc4122();
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
        return $this->signWithNativePhp($unsignedProfile) ?? throw new \UnexpectedValueException('Error signing mobileprofile.');
    }

    /**
     * Attempt to sign using native PHP openssl_pkcs7_sign.
     *
     * @return string|null The signed profile in DER format, or null if signing failed
     */
    private function signWithNativePhp(string $unsignedProfile): ?string
    {
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

            $result = openssl_cms_sign(
                input_filename: $tempUnsignedPath,
                output_filename: $tempSignedPath,
                certificate: 'file://' . $this->serverCertPath,
                private_key: ['file://' . $this->serverKeyPath, ''],
                headers: [],
                encoding: \OPENSSL_ENCODING_DER,
                untrusted_certificates_filename: CaBundle::getSystemCaRootBundlePath(),
            );

            if (!$result) {
                return null;
            }

            $signedContent = file_get_contents($tempSignedPath);
            if (false === $signedContent) {
                return null;
            }

            return $signedContent;
        } finally {
            fclose($tempUnsigned);
            fclose($tempSigned);
            @unlink($tempUnsignedPath);
            @unlink($tempSignedPath);
        }
    }
}
