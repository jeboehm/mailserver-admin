<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service\DKIM;

class KeyGenerationService
{
    public const string DIGEST_ALGORITHM = 'sha256';
    private const int KEY_LENGTH = 2048;

    public function extractPublicKey(string $privateKey): string
    {
        $res = openssl_pkey_get_private($privateKey);

        if (false === $res) {
            throw new \LogicException('Cannot read private key.');
        }

        return openssl_pkey_get_details($res)['key'];
    }

    public function createKeyPair(): KeyPair
    {
        $privateKey = '';
        $res = openssl_pkey_new(
            [
                'digest_alg' => self::DIGEST_ALGORITHM,
                'private_key_bits' => self::KEY_LENGTH,
                'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            ]
        );

        openssl_pkey_export($res, $privateKey);

        return new KeyPair(openssl_pkey_get_details($res)['key'], $privateKey);
    }
}
