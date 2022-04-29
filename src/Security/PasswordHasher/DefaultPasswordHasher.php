<?php

declare(strict_types=1);
/**
 * This file is part of the mailserver-admin package.
 * (c) Jeffrey Boehm <https://github.com/jeboehm/mailserver-admin>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Security\PasswordHasher;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

class DefaultPasswordHasher implements PasswordHasherInterface
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function needsRehash(string $hashedPassword): bool
    {
        return false;
    }

    public function hash(string $plainPassword): string
    {
        return crypt($plainPassword, sprintf('$5$rounds=5000$%s$', $this->secret));
    }

    public function verify(string $hashedPassword, string $plainPassword): bool
    {
        return hash_equals(
            $this->hash($plainPassword),
            $hashedPassword
        );
    }
}
