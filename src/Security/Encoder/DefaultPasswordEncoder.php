<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Security\Encoder;

use Symfony\Component\Security\Core\Encoder\PasswordEncoderInterface;

class DefaultPasswordEncoder implements PasswordEncoderInterface
{
    public function isPasswordValid($encoded, $raw, $salt): bool
    {
        return hash_equals(
            $this->encodePassword($raw, $salt),
            $encoded
        );
    }

    public function encodePassword($raw, $salt): string
    {
        return crypt($raw, sprintf('$5$rounds=5000$%s$', $salt));
    }
}
